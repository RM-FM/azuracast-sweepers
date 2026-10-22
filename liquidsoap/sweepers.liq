let sweepers = ()
let sweepers.plans = ref([])
let sweepers.pending = ref(null)
let sweepers.reset = ref(false)
let sweepers.now = ref(fun () -> 0.)
let sweepers.duck_gain = ref(1.)
let sweepers.duck_start = ref(-1.)
let sweepers.duck_end = ref(-1.)
let sweepers.seen = ref([])
let sweepers.metadata = ref([])
let sweepers.forwarded = ref([])

def sweepers.next_overlay() =
    music_clock = sweepers.now()
    now = music_clock()
    if null.defined(sweepers.pending()) then
        p = null.get(sweepers.pending())
        if azuracast.live_enabled() then
            sweepers.pending := null
            (source.fail() : source)
        elsif now >= p.due then
            sweepers.pending := null
            sweepers.reset := false
            if now > p.due + 0.1 then
                log(label="sweepers", "Late activation skipped: #{p.token}")
                (source.fail() : source)
            else
                sweepers.duck_start := now
                sweepers.duck_end := p.duck_end
                p.source
            end
        elsif sweepers.reset() then
            sweepers.reset := false
            (source.fail() : source)
        else
            null
        end
    elsif sweepers.reset() then
        sweepers.reset := false
        (source.fail() : source)
    else
        null
    end
end

let sweepers.jingles = source.dynamic(id="sweepers", track_sensitive=false, sweepers.next_overlay)

def sweepers.resolve_jingles() =
    measurements = ref([])
    result = azuracast.api_call("sweepers/catalog", "{}")
    if null.defined(result) then
        let json.parse (jobs : [{media_id: int, identity: string, uri: string}]) = null.get(result)
        list.iter(fun (job) -> begin
            r = request.create(job.uri)
            if request.resolve(timeout=15., r) then
                m = request.metadata(r)
                measurements := [{
                    media_id=job.media_id,
                    identity=job.identity,
                    metadata={
                        liq_cue_in=m["liq_cue_in"],
                        liq_cue_out=m["liq_cue_out"],
                        liq_cross_start_next=m["liq_cross_start_next"],
                        liq_fade_out=m["liq_fade_out"],
                        liq_amplify=m["liq_amplify"],
                        autocue_cue_in=m["autocue_cue_in"],
                        autocue_cue_out=m["autocue_cue_out"],
                        autocue_start_next=m["autocue_start_next"],
                        autocue_fade_out=m["autocue_fade_out"]
                    }
                }, ...measurements()]
            end
            request.destroy(r)
        end, jobs)
    end
    measurements()
end

let sweepers.original_next_song = azuracast.autodj_next_song

def azuracast.autodj_next_song() =
    next = sweepers.original_next_song()
    if null.defined(next) then
        r = null.get(next)
        try
            if request.resolve(timeout=60., r) then
                m = request.metadata(r)
                token = m["sq_id"]
                if token != "" and m["media_id"] != "" then
                    measurements = sweepers.resolve_jingles()
                    cue_in = float_of_string(default=0., m["liq_cue_in"])
                    cue_out = float_of_string(default=float_of_string(default=0., m["duration"]), m["liq_cue_out"])
                    transition = float_of_string(default=cue_out, m["liq_cross_start_next"])
                    window_end = if transition > cue_in then min(cue_out, transition) else cue_out end
                    body = json.stringify({
                        token=token,
                        media_id=m["media_id"],
                        jingles=measurements,
                        cue_in=cue_in,
                        cue_out=window_end
                    })
                    response = azuracast.api_call("sweepers/plan", body)
                    if null.defined(response) then
                        let json.parse (p : {
                            token: string, uri: string, offset: float,
                            duck_duration: float, gain: float, duck_gain: float
                        }) = null.get(response)
                        ready = ref(false)
                        overlay = source.fail()
                        overlay =
                            if p.uri != "" then
                                jr = request.create(resolve_metadata=false, p.uri)
                                if request.resolve(timeout=15., jr) then
                                    ready := true
                                    s = amplify(override=null, p.gain, request.once(jr))
                                    s.on_track(synchronous=false, fun (_) -> begin
                                        ignore(azuracast.api_call("sweepers/played", json.stringify({token=p.token})))
                                    end)
                                    s
                                else
                                    request.destroy(jr)
                                    overlay
                                end
                            else overlay end
                        overlay = sweepers.jingles.prepare(overlay)
                        sweepers.plans := list.prefix(16, [(token, {
                            source=overlay, ready=ready(), offset=p.offset,
                            duration=p.duck_duration, duck_gain=p.duck_gain
                        }), ...sweepers.plans()])
                    end
                end
            end
        catch err do
            log(label="sweepers", "Planning skipped: #{error.message(err)}")
        end
        r
    else
        null
    end
end

%include "sidechain.liq"

def sweepers.mix(music) =
    sweepers.now := music.time
    music.on_metadata(synchronous=true, fun (m) -> begin
        sweepers.metadata := m
        token = m["sq_id"]
        if token == "" or not list.mem(token, sweepers.seen()) then
            if token != "" then
                sweepers.seen := list.prefix(64, [token, ...sweepers.seen()])
            end
            sweepers.pending := null
            sweepers.reset := true
            sweepers.duck_end := -1.
            if list.assoc.mem(token, sweepers.plans()) then
                p = list.assoc(token, sweepers.plans())
                sweepers.duck_gain := p.duck_gain
                if p.ready then
                    sweepers.pending := {
                        source=p.source,
                        due=music.time() + p.offset,
                        duck_end=music.time() + p.offset + p.duration,
                        token=token
                    }
                end
            end
        end
    end)

    def blend() =
        now = music.time()
        if sweepers.jingles.is_ready() and now < sweepers.duck_end() then
            attack = min(1., max(0., (now - sweepers.duck_start()) / 0.15))
            release = min(1., max(0., (sweepers.duck_end() - now) / 0.15))
            (1. - sweepers.duck_gain()) * min(attack, release)
        else 0. end
    end

    let (dry, wet, jingle) = sweepers.sidechain(music, mksafe(sweepers.jingles))
    dry = amplify(override=null, {1. - blend()}, dry)
    wet = amplify(override=null, {blend()}, wet)
    mixed = add(normalize=false, [dry, wet, jingle])
    mixed.on_frame(before=true, synchronous=true, fun () -> begin
        m = sweepers.metadata()
        if m != [] and m != sweepers.forwarded() then
            sweepers.forwarded := m
            mixed.insert_metadata(m)
        end
    end)
    mixed
end
