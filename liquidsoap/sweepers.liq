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
let sweepers.settings = ref({
    enabled=false, revision="", minimum_songs=0,
    count_jingle_mode=true, jingle_playlists=[]
})
let sweepers.songs_since_jingle = ref(0)
let sweepers.previous_song = ref(false)

def sweepers.configure(s) =
    if s.revision == "" or s.minimum_songs < 0 or s.minimum_songs > 1000 then
        error.raise(error.failure, "Invalid live sweeper settings")
    end
    if s.revision != sweepers.settings().revision then
        sweepers.settings := s
        sweepers.catalog_at := -60.
        log(label="sweepers", "Live settings applied: #{s.revision}, enabled=#{s.enabled}, minimum_songs=#{s.minimum_songs}")
    end
end

def sweepers.current(revision) =
    s = sweepers.settings()
    s.enabled and revision == s.revision
end

def sweepers.notify(event, token) =
    if token != "" then
        if event == "played" then
            sweepers.songs_since_jingle := 0
            sweepers.previous_song := false
        end
        sweepers.events := list.prefix(128, [{event=event, token=token}, ...sweepers.events()])
        sweepers.plans := list.assoc.remove(token, sweepers.plans())
    end
end


def sweepers.is_jingle(m) =
    s = sweepers.settings()
    (s.count_jingle_mode and m["jingle_mode"] == "true") or
        list.mem(int_of_string(default=0, m["playlist_id"]), s.jingle_playlists)
end

def sweepers.track_allowed(m) =
    token = m["sq_id"]
    is_jingle = sweepers.is_jingle(m)
    is_song = token != "" and m["media_id"] != "" and not is_jingle
    if is_jingle or not is_song then
        sweepers.songs_since_jingle := 0
    elsif sweepers.previous_song() then
        sweepers.songs_since_jingle := min(1000, sweepers.songs_since_jingle() + 1)
    end
    sweepers.previous_song := is_song
    is_song and sweepers.songs_since_jingle() >= sweepers.settings().minimum_songs
end

def sweepers.next_overlay() =
    music_clock = sweepers.now()
    now = music_clock()
    list.iter(fun (entry) -> begin
        if not sweepers.current(snd(entry).revision) then
            sweepers.notify("release", fst(entry))
        end
    end, sweepers.plans())
    if azuracast.live_enabled() and sweepers.active() != "" then
        sweepers.notify("release", sweepers.active())
        sweepers.active := ""
        sweepers.pending := null
        sweepers.duck_end := -1.
        (source.fail() : source)
    elsif null.defined(sweepers.pending()) then
        p = null.get(sweepers.pending())
        if azuracast.live_enabled() or not sweepers.current(p.revision) then
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
    try
        response = azuracast.api_call(timeout=2., "sweepers/heartbeat", json.stringify({tokens=tokens, events=events}))
        if null.defined(response) then
            let json.parse (result : {
                success: bool,
                settings: {
                    enabled: bool, revision: string, minimum_songs: int,
                    count_jingle_mode: bool, jingle_playlists: [int]
                }
            }) = null.get(response)
            if not result.success then
                error.raise(error.failure, "Sweeper heartbeat rejected")
            end
            sweepers.configure(result.settings)
            sweepers.events := list.filter(fun (e) -> not list.mem(e, events), sweepers.events())
        end
    catch err do
        log(label="sweepers", "Live settings refresh failed: #{error.message(err)}")
    end
    5.
end

let sweepers.original_next_song = azuracast.autodj_next_song

def sweepers.next_song() =
    next = sweepers.original_next_song()
    if null.defined(next) then
        r = null.get(next)
        try
            if sweepers.settings().enabled and request.resolve(timeout=settings.azuracast.request_timeout(), r) then
                m = request.metadata(r)
                token = m["sq_id"]
                cue_in = float_of_string(default=0., m["liq_cue_in"])
                if token != "" and m["media_id"] != "" and not sweepers.is_jingle(m) and
                    not list.assoc.mem(token, sweepers.plans()) then
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
                            token: string, revision: string, uri: string, offset: float,
                            duck_duration: float, gain: float, duck_gain: float
                        }) = null.get(response)
                        ready = ref(false)
                        overlay = source.fail()
                        overlay =
                            if p.uri != "" and sweepers.current(p.revision) then
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
                            else
                                sweepers.notify("release", p.token)
                                overlay
                            end
                        if ready() and sweepers.current(p.revision) then
                            list.iter(fun (entry) -> sweepers.notify("release", fst(entry)), list.slice(offset=15, sweepers.plans()))
                            sweepers.plans := [(token, {
                                source=overlay, ready=true, offset=p.offset,
                                duration=p.duck_duration, duck_gain=p.duck_gain, revision=p.revision
                            }), ...list.assoc.remove(token, sweepers.plans())]
                        elsif ready() then
                            sweepers.notify("release", p.token)
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

    dynamic.on_track(synchronous=true, fun (_) -> azuracast.autodj_is_loading := false)
    thread.run(delay=120., { azuracast.autodj_is_loading := false })

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
            allowed = sweepers.track_allowed(m)
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
                if p.ready and allowed and sweepers.current(p.revision) then
                    sweepers.pending := {
                        source=p.source,
                        due=now() + p.offset,
                        duck_end=now() + p.offset + p.duration,
                        token=token,
                        revision=p.revision
                    }
                else
                    sweepers.notify("release", token)
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
