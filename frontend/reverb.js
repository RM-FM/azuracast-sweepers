// Same reflections as sweepers.reverb in liquidsoap/sweepers.liq (FFmpeg aecho); tests keep both in sync.
export const REVERB_DELAYS_MS = [41, 59, 83, 109, 137, 167, 199, 233, 271, 313];
export const REVERB_DECAYS = [0.32, 0.28, 0.25, 0.22, 0.19, 0.16, 0.13, 0.1, 0.08, 0.06];

// The module sends intensity / 100 as the level; off means no reflections.
export function reverbLevel(enabled, intensity) {
    const value = Number(intensity);
    return enabled && Number.isFinite(value) ? Math.min(Math.max(value, 0), 100) / 100 : 0;
}

/**
 * Plays one sweeper between its cue points with the reverb; the level can change while it plays.
 */
export function createReverbPreview(AudioContextClass, fetchAudio) {
    let context = null;
    let current = null;
    const buffers = new Map();

    async function buffer(url) {
        if (!buffers.has(url)) {
            buffers.set(url, fetchAudio(url).then((data) => context.decodeAudioData(data)).catch((error) => {
                buffers.delete(url);
                throw error;
            }));
        }
        return buffers.get(url);
    }

    function stop() {
        if (current) {
            current.source.onended = null;
            try { current.source.stop(); } catch { /* already stopped */ }
            current.source.disconnect();
            current = null;
        }
    }

    function setLevel(level) {
        if (current) {
            current.taps.forEach((tap, index) => {
                tap.gain.setTargetAtTime(REVERB_DECAYS[index] * level, context.currentTime, 0.02);
            });
        }
    }

    async function play(sweeper, level, onEnded = () => {}) {
        context ??= new AudioContextClass();
        if (context.state === "suspended") await context.resume();
        stop();
        const audio = await buffer(sweeper.audio_url);
        const source = context.createBufferSource();
        source.buffer = audio;
        source.connect(context.destination);
        const taps = REVERB_DELAYS_MS.map((delayMs, index) => {
            const delay = context.createDelay(1);
            delay.delayTime.value = delayMs / 1000;
            const gain = context.createGain();
            gain.gain.value = REVERB_DECAYS[index] * level;
            source.connect(delay);
            delay.connect(gain);
            gain.connect(context.destination);
            return gain;
        });
        const start = Math.max(0, Number(sweeper.cue_in) || 0);
        const end = Math.min(audio.duration, Number(sweeper.cue_out) || audio.duration);
        const playing = { source, taps };
        source.onended = () => {
            if (current === playing) current = null;
            onEnded();
        };
        current = playing;
        source.start(0, start, Math.max(0, end - start));
    }

    return {
        play,
        stop,
        setLevel,
        get playing() { return current !== null; },
        close() {
            stop();
            context?.close();
            context = null;
        },
    };
}
