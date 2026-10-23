let sweepers = ()
let sweepers.plans = ref([])
let sweepers.pending = ref(null)
let sweepers.reset = ref(false)
let sweepers.now = ref(fun () -> 0.)
let sweepers.duck_gain = ref(1.)
let sweepers.duck_start = ref(-1.)
let sweepers.duck_end = ref(-1.)
let sweepers.seen = ref([])
let sweepers.active = ref("")
let sweepers.events = ref([])
let sweepers.measurements = ref([])
let sweepers.catalog = ref([])
let sweepers.attempted = ref([])
let sweepers.catalog_at = ref(-60.)
let sweepers.started = ref(false)

def sweepers.notify(event, token) =
    if token != "" then
        sweepers.events := list.prefix(128, [{event=event, token=token}, ...sweepers.events()])
        sweepers.plans := list.assoc.remove(token, sweepers.plans())
    end
end


def sweepers.next_overlay() =
    music_clock = sweepers.now()
    now = music_clock()
    if azuracast.live_enabled() and sweepers.active() != "" then
        sweepers.notify("release", sweepers.active())
        sweepers.active := ""
        sweepers.pending := null
        sweepers.duck_end := -1.
        (source.fail() : source)
    elsif null.defined(sweepers.pending()) then
        p = null.get(sweepers.pending())
        if azuracast.live_enabled() then
            sweepers.notify("release", p.token)
            sweepers.pending := null
            (source.fail() : source)
        elsif now >= p.due then
            sweepers.pending := null
            sweepers.reset := false
            if now > p.due + 0.1 then
                sweepers.notify("release", p.token)
                log(label="sweepers", "Late activation skipped: #{p.token}")
                (source.fail() : source)
            else
                sweepers.active := p.token
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

let sweepers.jingles = source.dynamic(id="sweepers", self_sync=false, track_sensitive=false, sweepers.next_overlay)

def sweepers.refresh_catalog() =
    try
        if time() - sweepers.catalog_at() >= 30. then
            sweepers.catalog_at := time()
            result = azuracast.api_call(timeout=2., "sweepers/catalog", "{}")
            if null.defined(result) then
                let json.parse (jobs : [{media_id: int, identity: string, uri: string}]) = null.get(result)
                sweepers.catalog := jobs
                identities = list.map(fun (j) -> j.identity, jobs)
                sweepers.measurements := list.filter(fun (m) -> list.mem(m.identity, identities), sweepers.measurements())
                sweepers.attempted := list.filter(fun (a) -> list.mem(fst(a), identities), sweepers.attempted())
            end
        end
        missing = list.filter(fun (job) -> begin
            measured = list.exists(fun (m) -> m.identity == job.identity, sweepers.measurements())
            attempted = list.assoc(default=0., job.identity, sweepers.attempted())
            not measured and time() - attempted >= 60.
        end, sweepers.catalog())
        if missing != [] then
            job = list.hd(missing)
            sweepers.attempted := [(job.identity, time()), ...list.assoc.remove(job.identity, sweepers.attempted())]
            r = request.create(job.uri)
            try
                if request.resolve(timeout=10., r) then
                    m = request.metadata(r)
                    sweepers.measurements := [{
                        media_id=job.media_id, identity=job.identity,
                        metadata={
                            liq_cue_in=m["liq_cue_in"], liq_cue_out=m["liq_cue_out"],
                            liq_cross_start_next=m["liq_cross_start_next"], liq_fade_out=m["liq_fade_out"],
                            liq_amplify=m["liq_amplify"], autocue_cue_in=m["autocue_cue_in"],
                            autocue_cue_out=m["autocue_cue_out"], autocue_start_next=m["autocue_start_next"],
                            autocue_fade_out=m["autocue_fade_out"]
                        }
                    }, ...sweepers.measurements()]
                end
            catch err do
                log(label="sweepers", "Catalogue item skipped: #{error.message(err)}")
            end
            request.destroy(r)
        end
    catch err do
        log(label="sweepers", "Catalogue refresh skipped: #{error.message(err)}")
    end
    2.
end

def sweepers.heartbeat() =
    events = list.rev(sweepers.events())
    tokens = list.map(fst, sweepers.plans())
    response = azuracast.api_call(timeout=2., "sweepers/heartbeat", json.stringify({tokens=tokens, events=events}))
    if null.defined(response) then
        sweepers.events := list.filter(fun (e) -> not list.mem(e, events), sweepers.events())
    end
    5.
end

let sweepers.original_next_song = azuracast.autodj_next_song

def sweepers.next_song() =
    next = sweepers.original_next_song()
    if null.defined(next) then
        r = null.get(next)
        try
            if request.resolve(timeout=settings.azuracast.request_timeout(), r) then
                m = request.metadata(r)
                token = m["sq_id"]
                cue_in = float_of_string(default=0., m["liq_cue_in"])
                if token != "" and m["media_id"] != "" and not list.assoc.mem(token, sweepers.plans()) then
                    measurements = sweepers.measurements()
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
                    response = azuracast.api_call(timeout=1., "sweepers/plan", body)
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
                                if request.resolve(timeout=1., jr) then
                                    ready := true
                                    s = amplify(override=null, p.gain, request.once(jr))
                                    s.on_track(synchronous=true, fun (_) -> sweepers.notify("played", p.token))
                                    s
                                else
                                    request.destroy(jr)
                                    sweepers.notify("release", p.token)
                                    overlay
                                end
                            else overlay end
                        if ready() then
                            list.iter(fun (entry) -> sweepers.notify("release", fst(entry)), list.slice(offset=15, sweepers.plans()))
                            sweepers.plans := [(token, {
                                source=overlay, ready=true, offset=p.offset,
                                duration=p.duck_duration, duck_gain=p.duck_gain
                            }), ...list.assoc.remove(token, sweepers.plans())]
                        end
                    end
                end
            end
        catch err do
            sweepers.notify("release", list.assoc(default="", "sq_id", request.metadata(r)))
            log(label="sweepers", "Planning skipped: #{error.message(err)}")
        end
        r
    else
        null
    end
end

def sweepers.enable_autodj(s) =
    if not sweepers.started() then
        sweepers.started := true
        thread.run.recurrent(fast=false, sweepers.refresh_catalog)
        thread.run.recurrent(fast=false, sweepers.heartbeat)
    end
    dynamic = request.dynamic(
        id="next_song",
        timeout=settings.azuracast.request_timeout(),
        retry_delay=10.,
        sweepers.next_song
    )

    dynamic_startup = fallback(
        id = "dynamic_startup",
        track_sensitive = false,
        [
            dynamic,
            source.available(
                blank(id = "autodj_startup_blank", duration = 120.),
                predicate.activates({azuracast.autodj_is_loading()})
            )
        ]
    )

    s = fallback(id="autodj_fallback", track_sensitive = true, [dynamic_startup, s])

    ref_dynamic = ref(dynamic);
    thread.run.recurrent(delay=0.25, { azuracast.wait_for_next_song(ref_dynamic()) })

    s
end

%include "sidechain.liq"

def sweepers.mix(music) =
    def now() = float_of_int(clock(music.clock).ticks()) * frame.duration() end
    sweepers.now := now
    music.on_metadata(synchronous=true, fun (m) -> begin
        token = m["sq_id"]
        if token == "" or not list.mem(token, sweepers.seen()) then
            if token != "" then
                sweepers.seen := list.prefix(64, [token, ...sweepers.seen()])
            end
            if null.defined(sweepers.pending()) then
                sweepers.notify("release", null.get(sweepers.pending()).token)
            end
            sweepers.notify("release", sweepers.active())
            sweepers.active := ""
            sweepers.pending := null
            sweepers.reset := true
            sweepers.duck_end := -1.
            if list.assoc.mem(token, sweepers.plans()) then
                p = list.assoc(token, sweepers.plans())
                sweepers.duck_gain := p.duck_gain
                if p.ready then
                    sweepers.pending := {
                        source=p.source,
                        due=now() + p.offset,
                        duck_end=now() + p.offset + p.duration,
                        token=token
                    }
                end
            end
        end
    end)

    def blend() =
        now = now()
        if sweepers.jingles.is_ready() and now < sweepers.duck_end() then
            attack = min(1., max(0., (now - sweepers.duck_start()) / 0.15))
            release = min(1., max(0., (sweepers.duck_end() - now) / 0.15))
            (1. - sweepers.duck_gain()) * min(attack, release)
        else 0. end
    end

    mixed = sweepers.sidechain(blend=blend, music, mksafe(sweepers.jingles))
    mixed
end
