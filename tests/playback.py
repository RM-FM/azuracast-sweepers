#!/usr/bin/env python3
"""Render synthetic audio through the native AzuraCast request factory and mixer."""
import argparse
import json
import math
import re
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
parser.add_argument('--scenario', choices=['normal', 'skip', 'live', 'unavailable', 'spacing', 'playlist-spacing',
                                          'hot-enable', 'hot-disable', 'hot-pending', 'hot-change', 'hot-error',
                                          'before-jingle', 'computed-autocue', 'provided-autocue', 'arrange'], default='normal')
parser.add_argument('--runtime', type=Path, required=True)
args = parser.parse_args()
plugin = Path(__file__).resolve().parents[1]
rate = 44100
spacing = args.scenario in ('spacing', 'playlist-spacing')
autocue = args.scenario in ('computed-autocue', 'provided-autocue')
short_tracks = spacing or autocue or args.scenario in ('hot-enable', 'arrange')


def tone(path, hz, duration, silence_before=0., silence_after=0.):
    with wave.open(str(path), 'wb') as output:
        output.setparams((2, 2, rate, 0, 'NONE', 'not compressed'))
        frames = int(rate * (silence_before + duration + silence_after))
        start, end = int(rate * silence_before), int(rate * (silence_before + duration))
        output.writeframes(b''.join(struct.pack('<hh', *([int(6000 * math.sin(2 * math.pi * hz * i / rate)) if start <= i < end else 0] * 2))
                                    for i in range(frames)))


def amplitude(samples, hz, start, duration=.1):
    window = samples[int(start * rate):int((start + duration) * rate)]
    return 2 * abs(sum(x * complex(math.cos(2 * math.pi * hz * i / rate),
                                   math.sin(2 * math.pi * hz * i / rate)) for i, x in enumerate(window))) / len(window)


with tempfile.TemporaryDirectory(prefix='sweepers-playback-') as temporary:
    root = Path(temporary)
    tone(root / 'song.wav', 440, 1.2 if short_tracks else 8)
    tone(root / 'sweeper.wav', 880, .5 if short_tracks else 2)
    # A sweeper file with leading and trailing silence, for AutoCue measurement.
    tone(root / 'cued.wav', 660, 2, silence_before=.5, silence_after=1.)
    calls = []
    song_requests = 0
    heartbeat_requests = 0
    initial_settings = dict(enabled=args.scenario != 'hot-enable', revision='initial',
                            songs_after_jingle=1 if spacing else 0, songs_after_sweeper=1 if spacing else 0,
                            count_jingle_mode=True, jingle_playlists=[7])
    live_settings = initial_settings.copy()

    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *_):
            pass

        def do_POST(self):
            body = self.rfile.read(int(self.headers.get('Content-Length', 0)))
            calls.append((self.path, json.loads(body) if body else None, time.monotonic()))
            global song_requests, heartbeat_requests
            if self.path == '/nextsong':
                song_requests += 1
                token = str(122 + song_requests) if short_tracks else ('123' if song_requests == 1 else '124')
                jingle = ('jingle_mode="true",' if args.scenario == 'spacing' else 'playlist_id="7",') if spacing and song_requests <= 2 else ''
                duration = 1.2 if short_tracks else 8
                value = {'uri': f'annotate:liq_disable_autocue="true",sq_id="{token}",{jingle}media_id="1",liq_cue_in="0",liq_cue_out="{duration}":{root}/song.wav'}
            elif self.path == '/sweepers/catalog':
                time.sleep(1.5)
                value = []
                if args.scenario == 'computed-autocue':
                    value = [{'media_id': 9, 'identity': 'cued', 'uri': f'{root}/cued.wav'}]
                elif args.scenario == 'provided-autocue':
                    value = [{'media_id': 9, 'identity': 'cued', 'uri': (
                        'annotate:azuracast_autocue="true",autocue_cue_in="0.25",autocue_cue_out="2.75",'
                        f'autocue_fade_in="0.00",autocue_fade_out="0.50",liq_amplify="-3.00 dB":{root}/cued.wav')}]
            elif self.path == '/sweepers/plan':
                value = dict(token=json.loads(body)['token'], revision=live_settings['revision'],
                             uri=f'annotate:liq_cue_in="0",liq_cue_out="{0.5 if short_tracks else 2}":{root}/sweeper.wav' if short_tracks or json.loads(body)['token'] == '123' else '',
                             offset=.1 if short_tracks else (3. if args.scenario in ('skip', 'hot-pending', 'hot-change', 'before-jingle') else 1.),
                             duck_duration=.3 if short_tracks else 1., gain=1., duck_gain=.4467)
            elif self.path == '/sweepers/arrange':
                moved = args.scenario == 'arrange' and not any(path == '/sweepers/arrange' for path, _, _ in calls[:-1])
                value = {'moved': '130', 'before': '125'} if moved else {'moved': '', 'before': ''}
            elif self.path == '/sweepers/heartbeat':
                heartbeat_requests += 1
                if heartbeat_requests >= 2 and args.scenario in ('hot-enable', 'hot-disable', 'hot-pending', 'hot-change'):
                    live_settings.update(enabled=args.scenario in ('hot-enable', 'hot-change'), revision='changed')
                value = {'success': True, 'settings': live_settings.copy(), 'blocked': []}
                if heartbeat_requests >= 2 and args.scenario == 'before-jingle':
                    value['blocked'] = ['123']
                if heartbeat_requests == 2 and args.scenario == 'hot-error':
                    value = {'success': False}
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
    if args.scenario.startswith('hot-') or args.scenario == 'before-jingle':
        action = 'thread.run(delay=1.6, {ignore(sweepers.heartbeat())})'
    if action:
        action = f'''action_started = ref(false)
radio.on_metadata(synchronous=true, fun (m) -> begin
    if m["sq_id"] != "" and not action_started() then
        action_started := true
        {action}
    end
end)
'''
    script.write_text(f'''settings.init.allow_root := true
%include {json.dumps(str(args.runtime.resolve() / 'azuracast.liq'))}
%include {json.dumps(str(plugin / 'liquidsoap/sweepers.liq'))}
sweepers.load(version={json.dumps('test')}, {{enabled={'false' if args.scenario == 'hot-enable' else 'true'}, revision="initial",
    songs_after_jingle={1 if spacing else 0}, songs_after_sweeper={1 if spacing else 0},
    count_jingle_mode=true, jingle_playlists=[7]}})
settings.azuracast.api_url := "http://127.0.0.1:{server.server_port}"
radio = sweepers.enable_autodj(fallback([blank(duration=0.1)]))
music = radio
radio = sweepers.mix(radio)
{action}
radio.on_metadata(synchronous=true, fun (m) -> print("SONG_METADATA #{{m}}"))
output.file(%wav, {json.dumps(str(root / 'mix.wav'))}, radio)
thread.run(delay=6.6, {{ignore(sweepers.heartbeat())}})
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
    metadata = [int(re.search(r'\("sq_id", "(\d+)"\)', line)[1])
                for line in result.stdout.splitlines() if line.startswith('SONG_METADATA') and '("sq_id",' in line]
    assert (124 if spacing else 123) in metadata, 'Song metadata lost'
    events = [event for path, body, _ in calls if path == '/sweepers/heartbeat'
              for event in body.get('events', [])]
    assert all(body.get('version') == 'test' for path, body, _ in calls if path == '/sweepers/heartbeat'), \
        'Heartbeats must report the loaded module version'
    assert 'Queue arrangement skipped' not in result.stdout, ('Arrangement call failed', result.stdout[-2000:])
    if spacing:
        played = {e['token'] for e in events if e['event'] == 'played'}
        assert '124' not in played and '125' not in played, ('Jingle or immediately following song overlaid', played)
        assert '126' in played, ('Sweeper missing after one intervening song', played)
        assert '127' not in played, ('Song carrying sweeper incorrectly counted as intervening song', played)
        for token in (124, 125, 127):
            at = onset + (token - metadata[0]) * 1.2 + .25
            assert amplitude(samples, 880, at) < 50, ('Unexpected sweeper', token)
        at = onset + (126 - metadata[0]) * 1.2 + .25
        assert amplitude(samples, 880, at) > 5000, 'Allowed sweeper missing'
    elif args.scenario == 'hot-enable':
        played = {e['token'] for e in events if e['event'] == 'played'}
        assert played, 'Enabling did not activate sweepers without a restart'
        assert not played.intersection({'123', '124'}), 'Disabled startup scheduled a sweeper'
        assert max(amplitude(samples, 880, t / 10) for t in range(25, 60)) > 5000, 'Enabled sweeper inaudible'
    elif args.scenario in ('skip', 'unavailable', 'hot-pending', 'hot-change', 'before-jingle'):
        assert max(amplitude(samples, 880, t / 10) for t in range(5, 60)) < 100, 'Unexpected sweeper'
        if args.scenario in ('skip', 'hot-pending', 'hot-change', 'before-jingle'):
            assert {'event': 'release', 'token': '123'} in events, 'Skipped reservation not released'
            assert not any(e['event'] == 'played' for e in events), 'Cancelled plan counted as playback'
        if args.scenario == 'before-jingle':
            assert 'Sweeper cancelled: jingle queued' in result.stdout, 'Queued jingle cancellation not logged'
    elif autocue:
        measured = [jingle for path, body, _ in calls if path == '/sweepers/plan' for jingle in body.get('jingles', [])]
        assert measured, ('The sweeper was never measured', result.stdout[-2000:])
        jingle = measured[-1]
        timing = jingle['metadata']
        if args.scenario == 'computed-autocue':
            assert jingle['source'] == 'computed', jingle
            # Values come from Liquidsoap's built-in AutoCue; the plugin must pass them on unchanged.
            assert 0 <= float(timing['autocue_cue_in']) <= .6, ('Implausible cue-in', timing)
            assert 2.2 <= float(timing['autocue_cue_out']) <= 3.5, ('Implausible cue-out', timing)
            assert float(timing['autocue_fade_out']) >= 0, ('Missing fade-out', timing)
            assert timing['liq_amplify'].endswith('dB'), ('Loudness not measured', timing)
            assert 'AutoCue computed for sweeper 9' in result.stdout, 'Fallback not logged'
        else:
            assert jingle['source'] == 'azuracast', jingle
            assert (timing['autocue_cue_in'], timing['autocue_cue_out'], timing['liq_amplify']) == ('0.25', '2.75', '-3.00 dB'), timing
            assert 'AutoCue computed' not in result.stdout, 'AzuraCast values must not be recomputed'
    elif args.scenario == 'arrange':
        fetches = [(path, body) for path, body, _ in calls if path in ('/sweepers/arrange', '/nextsong')]
        arranged = [body for path, body in fetches if path == '/sweepers/arrange']
        assert arranged, ('The module never asked to arrange the queue', result.stdout[-2000:])
        for index, (path, _) in enumerate(fetches):
            if path == '/sweepers/arrange':
                assert index + 1 < len(fetches) and fetches[index + 1][0] == '/nextsong', \
                    'Arrangement must run right before AzuraCast sends the next song'
        for body in arranged:
            assert int(body['counted']) in metadata, ('Counted token was never played', body)
            assert all(isinstance(body[key], int) and not isinstance(body[key], bool)
                       for key in ('songs_since_jingle', 'songs_since_sweeper')), body
            assert all(isinstance(body[key], bool) for key in ('previous_song', 'previous_clear')), body
            assert isinstance(body['jingles'], list), body
        assert 'Song with intro moved forward: queue 130 now plays before 125' in result.stdout, \
            'Reordering was not logged'
    else:
        ducked = amplitude(samples, 440, onset + 1.4)
        assert .40 < ducked / normal < .65, (normal, ducked)
        assert amplitude(samples, 880, onset + .7) < 50, 'Sweeper started early'
        assert amplitude(samples, 880, onset + 1.2) > 5000, 'Sweeper did not start at offset'
        assert any(e['event'] == 'played' for e in events), 'Playback acknowledgment missing'
        returned = amplitude(samples, 440, onset + 2.3)
        assert .95 < returned / normal < 1.05, (normal, returned)
        if args.scenario == 'live':
            assert amplitude(samples, 880, onset + 2.3) < 50, 'Live takeover did not cancel sweeper'
            assert {'event': 'release', 'token': '123'} in events, 'Live takeover did not release reservation'
        else:
            assert amplitude(samples, 880, onset + 2.5) > 5000, 'Reverb tail cut at handover'
            assert amplitude(samples, 880, onset + 3.3) < 50, 'Sweeper ran past cue-out'
        if args.scenario == 'normal':
            assert {'event': 'release', 'token': '124'} not in events, 'Empty plans must not emit release events'
    if args.scenario == 'hot-error':
        assert 'Live settings refresh failed:' in result.stdout, 'Invalid heartbeat was not reported'
        assert 'Live settings applied: changed' not in result.stdout, 'Invalid heartbeat changed settings'
        assert sum(e == {'event': 'played', 'token': '123'} for e in events) >= 2, 'Unacknowledged event was not retried'
    elif args.scenario.startswith('hot-'):
        assert 'Live settings applied: changed' in result.stdout, 'Heartbeat did not apply live settings'
    print(f'Native playback passed: {args.scenario}, onset={onset:.2f}s.')
