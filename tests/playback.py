#!/usr/bin/env python3
"""Render synthetic audio through the native AzuraCast request factory and mixer."""
import argparse
import json
import math
from pathlib import Path
import shlex
import struct
import subprocess
import tempfile
import threading
import time
import wave
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

parser = argparse.ArgumentParser()
parser.add_argument('--liquidsoap', default='liquidsoap')
parser.add_argument('--scenario', choices=['normal', 'skip', 'live', 'unavailable'], default='normal')
parser.add_argument('--runtime', type=Path, required=True)
args = parser.parse_args()
plugin = Path(__file__).resolve().parents[1]
rate = 44100


def tone(path, hz, duration):
    with wave.open(str(path), 'wb') as output:
        output.setparams((2, 2, rate, 0, 'NONE', 'not compressed'))
        output.writeframes(b''.join(struct.pack('<hh', *([int(6000 * math.sin(2 * math.pi * hz * i / rate))] * 2))
                                    for i in range(int(rate * duration))))


def amplitude(samples, hz, start, duration=.1):
    window = samples[int(start * rate):int((start + duration) * rate)]
    return 2 * abs(sum(x * complex(math.cos(2 * math.pi * hz * i / rate),
                                   math.sin(2 * math.pi * hz * i / rate)) for i, x in enumerate(window))) / len(window)


with tempfile.TemporaryDirectory(prefix='sweepers-playback-') as temporary:
    root = Path(temporary)
    tone(root / 'song.wav', 440, 8)
    tone(root / 'sweeper.wav', 880, 2)
    calls = []
    song_requests = 0

    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *_):
            pass

        def do_POST(self):
            body = self.rfile.read(int(self.headers.get('Content-Length', 0)))
            calls.append((self.path, json.loads(body) if body else None, time.monotonic()))
            global song_requests
            if self.path == '/nextsong':
                song_requests += 1
                token = '123' if song_requests == 1 else '124'
                value = {'uri': f'annotate:liq_disable_autocue="true",sq_id="{token}",media_id="1",liq_cue_in="0",liq_cue_out="8":{root}/song.wav'}
            elif self.path == '/sweepers/catalog':
                time.sleep(1.5)
                value = []
            elif self.path == '/sweepers/plan':
                value = dict(token='123', uri=f'annotate:liq_cue_in="0",liq_cue_out="2":{root}/sweeper.wav' if json.loads(body)['token'] == '123' else '',
                             offset=3. if args.scenario == 'skip' else 1., duck_duration=1., gain=1., duck_gain=.4467)
            else:
                value = {'success': True}
            self.send_response(503 if args.scenario == 'unavailable' and self.path == '/sweepers/plan' else 200)
            self.end_headers()
            try:
                self.wfile.write(json.dumps(value).encode())
            except BrokenPipeError:
                pass

    server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    script = root / 'playback.liq'
    action = {'skip': 'thread.run(delay=1.2, {music.skip()})', 'live': 'thread.run(delay=2., {azuracast.live_enabled := true})'}.get(args.scenario, '')
    script.write_text(f'''settings.init.allow_root := true
%include {json.dumps(str(args.runtime.resolve() / 'azuracast.liq'))}
%include {json.dumps(str(plugin / 'liquidsoap/sweepers.liq'))}
settings.azuracast.api_url := "http://127.0.0.1:{server.server_port}"
radio = sweepers.enable_autodj(fallback([blank(duration=0.1)]))
music = radio
radio = sweepers.mix(radio)
{action}
radio.on_metadata(synchronous=true, fun (m) -> print("SONG_METADATA #{{m}}"))
output.file(%wav, {json.dumps(str(root / 'mix.wav'))}, radio)
thread.run(delay=7., {{shutdown()}})
''')
    result = subprocess.run([*shlex.split(args.liquidsoap), str(script)], stdout=subprocess.PIPE,
                            stderr=subprocess.STDOUT, text=True, timeout=45)
    server.shutdown()
    (plugin / '.checks').mkdir(exist_ok=True)
    (plugin / '.checks' / f'playback-{args.scenario}.log').write_text(result.stdout + '\n' + repr(calls))
    if result.returncode:
        raise AssertionError(f'Liquidsoap failed; see .checks/playback-{args.scenario}.log')
    with wave.open(str(root / 'mix.wav')) as audio:
        assert audio.getframerate() == rate
        raw = audio.readframes(rate * 15)
        samples = struct.unpack('<' + 'h' * (len(raw) // 2), raw)[::2]
    (plugin / '.checks' / f'playback-{args.scenario}.wav').write_bytes((root / 'mix.wav').read_bytes())
    onset = next(t / 100 for t in range(500) if amplitude(samples, 440, t / 100, .02) > 3000)
    normal = amplitude(samples, 440, onset + .5)
    assert any(path == '/sweepers/plan' for path, _, _ in calls), 'Native factory bypassed plugin'
    assert 'sq_id", "123' in result.stdout, 'Song metadata lost'
    events = [event for path, body, _ in calls if path == '/sweepers/heartbeat'
              for event in body.get('events', [])]
    if args.scenario in ('skip', 'unavailable'):
        assert max(amplitude(samples, 880, t / 10) for t in range(5, 60)) < 100, 'Unexpected sweeper'
        if args.scenario == 'skip':
            assert {'event': 'release', 'token': '123'} in events, 'Skipped reservation not released'
            assert not any(e['event'] == 'played' for e in events), 'Skip counted as playback'
    else:
        ducked = amplitude(samples, 440, onset + 1.4)
        assert .40 < ducked / normal < .65, (normal, ducked)
        assert amplitude(samples, 880, onset + .7) < 50, 'Sweeper started early'
        assert amplitude(samples, 880, onset + 1.2) > 5000, 'Sweeper did not start at offset'
        assert any(e['event'] == 'played' for e in events), 'Playback acknowledgment missing'
        returned = amplitude(samples, 440, onset + 2.3)
        assert .95 < returned / normal < 1.05, (normal, returned)
        if args.scenario == 'live':
            assert amplitude(samples, 880, 2.3) < 50, 'Live takeover did not cancel sweeper'
            assert {'event': 'release', 'token': '123'} in events, 'Live takeover did not release reservation'
        else:
            assert amplitude(samples, 880, onset + 2.5) > 5000, 'Reverb tail cut at handover'
            assert amplitude(samples, 880, onset + 3.3) < 50, 'Sweeper ran past cue-out'
    print(f'Native playback passed: {args.scenario}, onset={onset:.2f}s.')
