// Same chain as sweepers.reverb in liquidsoap/sweepers.liq (FFmpeg); tests keep both in sync.
export const REVERB_DELAYS_MS = [37, 53, 67, 79, 97, 113, 131, 149, 173, 199, 227, 257, 293, 331, 373, 419];
export const REVERB_DECAYS = [
    0.203, 0.177, 0.157, 0.141, 0.121, 0.106, 0.09, 0.077, 0.063, 0.05, 0.039, 0.031, 0.022, 0.016, 0.011, 0.008,
];
// Only the reverb copy is band-limited; the voice itself stays unchanged.
export const REVERB_HIGHPASS_HZ = 250;
export const REVERB_LOWPASS_HZ = 3000;

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
        // FFmpeg highpass/lowpass default to two poles with Q 0.707, like Web Audio biquads.
        const highpass = context.createBiquadFilter();
        highpass.type = "highpass";
        highpass.frequency.value = REVERB_HIGHPASS_HZ;
        highpass.Q.value = Math.SQRT1_2;
        const lowpass = context.createBiquadFilter();
        lowpass.type = "lowpass";
        lowpass.frequency.value = REVERB_LOWPASS_HZ;
        lowpass.Q.value = Math.SQRT1_2;
        source.connect(highpass);
        highpass.connect(lowpass);
        const taps = REVERB_DELAYS_MS.map((delayMs, index) => {
            const delay = context.createDelay(1);
            delay.delayTime.value = delayMs / 1000;
            const gain = context.createGain();
            gain.gain.value = REVERB_DECAYS[index] * level;
            lowpass.connect(delay);
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
