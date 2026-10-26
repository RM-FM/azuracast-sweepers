// Minimal Web Audio stand-in for the reverb preview tests.
export class FakeAudioContext {
    constructor() {
        this.state = "running";
        this.currentTime = 0;
        this.destination = { name: "destination" };
        this.sources = [];
        this.gains = [];
        this.delays = [];
        this.closed = false;
        FakeAudioContext.last = this;
    }

    async decodeAudioData() { return { duration: 3 }; }
    async resume() { this.state = "running"; }
    close() { this.closed = true; }

    createBufferSource() {
        const source = {
            connections: [],
            connect(node) { this.connections.push(node); },
            disconnect() { this.disconnected = true; },
            start(...args) { this.started = args; },
            stop() { this.stopped = true; },
            onended: null,
        };
        this.sources.push(source);
        return source;
    }

    createDelay(max) {
        const delay = { max, delayTime: { value: 0 }, connect(node) { this.output = node; } };
        this.delays.push(delay);
        return delay;
    }

    createGain() {
        const node = {
            gain: { value: 1, setTargetAtTime(value) { this.target = value; } },
            connect(target) { this.output = target; },
        };
        this.gains.push(node);
        return node;
    }
}
