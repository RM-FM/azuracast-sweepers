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
    enabled=false, revision="", songs_after_jingle=0, songs_after_sweeper=0,
    count_jingle_mode=true, jingle_playlists=[]
})
let sweepers.blocked = ref([])
let sweepers.version = ref("")
let sweepers.songs_since_jingle = ref(0)
let sweepers.songs_since_sweeper = ref(0)
let sweepers.previous_song = ref(false)
let sweepers.previous_clear = ref(false)
let sweepers.counted = ref("")

# azuracast.api_call logs every request at the default level; the heartbeat runs every five seconds.
def sweepers.api_call(~timeout, endpoint, payload) =
    log(level=4, label="sweepers", "API #{endpoint} - Sending: #{payload}")
    try
        response = http.post(
            "#{settings.azuracast.api_url()}/#{endpoint}",
            headers=[
                ("Content-Type", "application/json"),
                ("User-Agent", "Liquidsoap AzuraCast"),
                ("X-Liquidsoap-Api-Key", "#{settings.azuracast.api_key()}")
            ],
            timeout=timeout,
            data=payload
        )
        if response.status_code == 200 then
            log(level=4, label="sweepers", "API #{endpoint} - Response (200): #{response}")
            "#{response}"
        else
            log(level=2, label="sweepers", "API #{endpoint} - Response (#{response.status_code}): #{response}")
            null
        end
    catch err do
        log(level=2, label="sweepers", "API #{endpoint} - Error: #{error.kind(err)} - #{error.message(err)}")
        null
    end
end

def sweepers.configure(s) =
    if s.revision == "" or s.songs_after_jingle < 0 or s.songs_after_jingle > 1000 or
        s.songs_after_sweeper < 0 or s.songs_after_sweeper > 1000 then
        error.raise(error.failure, "Invalid live sweeper settings")
    end
    if s.revision != sweepers.settings().revision then
        sweepers.settings := s
        sweepers.catalog_at := -60.
        log(label="sweepers", "Live settings applied: #{s.revision}, enabled=#{s.enabled}, songs_after_jingle=#{s.songs_after_jingle}, songs_after_sweeper=#{s.songs_after_sweeper}")
    end
end

# Must never fail station startup; invalid settings keep sweepers disabled.
def sweepers.load(~version, s) =
    sweepers.version := version
    try
        sweepers.configure(s)
    catch err do
        log(label="sweepers", "Initial settings ignored: #{error.message(err)}")
    end
end

# A prepared plan carries its own timing, so later settings changes only cancel it by disabling sweepers.
def sweepers.current(token) =
    sweepers.settings().enabled and not list.mem(token, sweepers.blocked())
end

def sweepers.stale_reason(token) =
    if list.mem(token, sweepers.blocked()) then "jingle_follows" else "disabled" end
end

def sweepers.event(~jingle_id=0, ~offset=0., event, token, detail, m) =
    sweepers.events := list.prefix(128, [{
        event=event, token=token, detail=detail,
        at=time(),
        media_id=m["media_id"], title=m["title"], artist=m["artist"],
        jingle_id=jingle_id, offset=offset
    }, ...sweepers.events()])
end

def sweepers.notify(~detail="", ~jingle_id=0, ~offset=0., ~metadata=[], event, token) =
    if token != "" then
        if event == "played" then
            log(label="sweepers", "Sweeper started for queue #{token}")
            sweepers.songs_since_sweeper := 0
            sweepers.previous_clear := false
        end
        sweepers.event(jingle_id=jingle_id, offset=offset, event, token, detail, metadata)
        sweepers.plans := list.assoc.remove(token, sweepers.plans())
    end
end


def sweepers.is_jingle(m) =
    s = sweepers.settings()
    (s.count_jingle_mode and m["jingle_mode"] == "true") or
        list.mem(int_of_string(default=0, m["playlist_id"]), s.jingle_playlists)
end

# Also advances the spacing counters; "" means the track may carry a sweeper.
def sweepers.track_reason(m) =
    token = m["sq_id"]
    sweepers.counted := token
    is_jingle = sweepers.is_jingle(m)
    is_song = token != "" and m["media_id"] != "" and not is_jingle
    if is_jingle then
        sweepers.songs_since_jingle := 0
    elsif not is_song then
        sweepers.songs_since_jingle := 0
        sweepers.songs_since_sweeper := 0
    else
        if sweepers.previous_song() then
            sweepers.songs_since_jingle := min(1000, sweepers.songs_since_jingle() + 1)
        end
        if sweepers.previous_clear() then
            sweepers.songs_since_sweeper := min(1000, sweepers.songs_since_sweeper() + 1)
        end
    end
    sweepers.previous_song := is_song
    sweepers.previous_clear := is_song
    s = sweepers.settings()
    if is_jingle then "jingle"
    elsif not is_song then "other"
    elsif sweepers.songs_since_jingle() < s.songs_after_jingle then "after_jingle"
    elsif sweepers.songs_since_sweeper() < s.songs_after_sweeper then "after_sweeper"
    else "" end
end

def sweepers.track_allowed(m) =
    sweepers.track_reason(m) == ""
end

def sweepers.next_overlay() =
    music_clock = sweepers.now()
    now = music_clock()
    list.iter(fun (entry) -> begin
        if not sweepers.current(fst(entry)) then
            sweepers.notify(detail=sweepers.stale_reason(fst(entry)), "release", fst(entry))
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
        if azuracast.live_enabled() or not sweepers.current(p.token) then
            sweepers.notify(
                detail=if azuracast.live_enabled() then "live" else sweepers.stale_reason(p.token) end,
                "release", p.token
            )
            sweepers.pending := null
            (source.fail() : source)
        elsif now >= p.due then
            sweepers.pending := null
            sweepers.reset := false
            if now > p.due + 0.1 then
                sweepers.notify(detail="late", "release", p.token)
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

# Only used when AzuraCast supplies no cue values; never written to AzuraCast's cache.
def sweepers.compute_autocue(m, filename) =
    computed = autocue.internal.implementation(request_metadata=m, file_metadata=m, filename)
    if null.defined(computed) then
        c = null.get(computed)
        start_next = c?.start_next
        loudness = c?.amplify
        [
            ("autocue_cue_in", "#{c.cue_in}"),
            ("autocue_cue_out", "#{c.cue_out}"),
            ("autocue_fade_out", "#{c.fade_out}"),
            ("autocue_start_next", if null.defined(start_next) then "#{null.get(start_next)}" else "" end),
            ("liq_amplify", if null.defined(loudness) then null.get(loudness) else m["liq_amplify"] end),
            ...m
        ]
    else
        m
    end
end

def sweepers.refresh_catalog() =
    try
        # Settings changes reset catalog_at.
        if time() - sweepers.catalog_at() >= 60. then
            sweepers.catalog_at := time()
            result = sweepers.api_call(timeout=2., "sweepers/catalog", "{}")
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
                    resolved = request.metadata(r)
                    provided = ((resolved["liq_cue_in"] != "" or resolved["autocue_cue_in"] != "") and
                        (resolved["liq_cue_out"] != "" or resolved["autocue_cue_out"] != ""))
                    m = if provided then resolved else sweepers.compute_autocue(resolved, request.filename(r)) end
                    origin = if provided then "azuracast" elsif m["autocue_cue_out"] != "" then "computed" else "none" end
                    if origin == "computed" then
                        log(label="sweepers", "AutoCue computed for sweeper #{job.media_id}: AzuraCast supplied no cue values")
                    elsif origin == "none" then
                        log(label="sweepers", "No cue values for sweeper #{job.media_id}; it will not be used")
                    end
                    sweepers.measurements := [{
                        media_id=job.media_id, identity=job.identity, source=origin,
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
        response = sweepers.api_call(timeout=2., "sweepers/heartbeat", json.stringify({
            tokens=tokens, events=events, version=sweepers.version()
        }))
        if null.defined(response) then
            let json.parse (result : {
                success: bool,
                settings: {
                    enabled: bool, revision: string, songs_after_jingle: int, songs_after_sweeper: int,
                    count_jingle_mode: bool, jingle_playlists: [int]
                },
                blocked: [string]
            }) = null.get(response)
            if not result.success then
                error.raise(error.failure, "Sweeper heartbeat rejected")
            end
            sweepers.configure(result.settings)
            list.iter(fun (token) -> begin
                if list.assoc.mem(token, sweepers.plans()) and not list.mem(token, sweepers.blocked()) then
                    log(label="sweepers", "Sweeper cancelled: jingle queued within the configured songs after #{token}")
                end
            end, result.blocked)
            sweepers.blocked := result.blocked
            sweepers.events := list.filter(fun (e) -> not list.mem(e, events), sweepers.events())
        end
    catch err do
        log(label="sweepers", "Live settings refresh failed: #{error.message(err)}")
    end
    5.
end

let sweepers.original_next_song = azuracast.autodj_next_song

# Must match frontend/reverb.js. FFmpeg filters: Liquidsoap's own echo and comb add no tail here.
let sweepers.reverb_delays = [37, 53, 67, 79, 97, 113, 131, 149, 173, 199, 227, 257, 293, 331, 373, 419]
let sweepers.reverb_decays = [
    0.203, 0.177, 0.157, 0.141, 0.121, 0.106, 0.09, 0.077, 0.063, 0.05, 0.039, 0.031, 0.022, 0.016, 0.011, 0.008
]

def sweepers.reverb(level, length, s) =
    # Pads the tail with silence; sequence() would skip a request that is not ready yet.
    padded = max_duration(length + 1.5, add(normalize=false, [s, blank()]))
    def graph(g) =
        let {audio} = source.tracks(ffmpeg.raw.encode.audio(%ffmpeg(%audio.raw), padded))
        voice = ffmpeg.filter.audio.input(g, audio)
        let (parts, _) = ffmpeg.filter.asplit(outputs=2, g, voice)
        wet = ffmpeg.filter.highpass(g, list.nth(parts, 1), frequency=250.)
        wet = ffmpeg.filter.lowpass(g, wet, frequency=3000.)
        wet = ffmpeg.filter.aecho(
            g, wet, in_gain=0., out_gain=1.,
            delays=string.concat(separator="|", list.map(fun (ms) -> "#{ms}", sweepers.reverb_delays)),
            decays=string.concat(separator="|", list.map(fun (decay) -> "#{decay * level}", sweepers.reverb_decays))
        )
        mixed = ffmpeg.filter.amix(g, inputs=2, normalize=false, [list.nth(parts, 0), wet], [])
        source({audio=ffmpeg.filter.audio.output(g, mixed)})
    end
    ffmpeg.raw.decode.audio(ffmpeg.filter.create(graph))
end

# Runs right before AzuraCast sends its next song, so nothing else can reorder the queue meanwhile.
def sweepers.arrange() =
    if sweepers.settings().enabled and sweepers.counted() != "" and not azuracast.live_enabled() then
        try
            response = sweepers.api_call(timeout=2., "sweepers/arrange", json.stringify({
                counted=sweepers.counted(),
                budget=1.5,
                release_spacing=true,
                songs_since_jingle=sweepers.songs_since_jingle(),
                songs_since_sweeper=sweepers.songs_since_sweeper(),
                previous_song=sweepers.previous_song(),
                previous_clear=sweepers.previous_clear(),
                jingles=sweepers.measurements()
            }))
            if null.defined(response) then
                let json.parse (r : {moved?: string, before?: string, released?: [string]}) = null.get(response)
                released = null.get(default=[], r?.released)
                list.iter(fun (token) -> sweepers.notify("release", token), released)
                if released != [] then
                    ignore(sweepers.heartbeat())
                end
                moved = null.get(default="", r?.moved)
                before = null.get(default="", r?.before)
                if moved != "" then
                    log(label="sweepers", "Song with intro moved forward: queue #{moved} now plays before #{before}")
                end
            end
        catch err do
            log(label="sweepers", "Queue arrangement skipped: #{error.message(err)}")
        end
    end
end

def sweepers.next_song() =
    sweepers.arrange()
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
                    response = ref(sweepers.api_call(timeout=1., "sweepers/plan", body))
                    if not null.defined(response()) then
                        log(label="sweepers", "Retrying planning for queue #{token}")
                        response := sweepers.api_call(timeout=1., "sweepers/plan", body)
                    end
                    if null.defined(response()) then
                        let json.parse (p : {
                            token: string, jingle_id?: int, uri: string, offset: float,
                            duck_duration: float, gain: float, duck_gain: float, reverb?: float, length?: float
                        }) = null.get(response())
                        ready = ref(false)
                        overlay = source.fail()
                        overlay =
                            if p.uri != "" and sweepers.current(p.token) then
                                jr = request.create(resolve_metadata=false, p.uri)
                                if request.resolve(timeout=1., jr) then
                                    ready := true
                                    s = amplify(override=null, p.gain, request.once(jr))
                                    s.on_track(synchronous=true, fun (_) -> begin
                                        if sweepers.active() == p.token then
                                            sweepers.notify(
                                                jingle_id=null.get(default=0, p?.jingle_id),
                                                offset=p.offset, metadata=m, "played", p.token
                                            )
                                        else
                                            log(level=2, label="sweepers", "Inactive sweeper source started for queue #{p.token}")
                                        end
                                    end)
                                    reverb = null.get(default=0., p?.reverb)
                                    length = null.get(default=0., p?.length)
                                    if reverb > 0. and length > 0. then sweepers.reverb(reverb, length, s) else s end
                                else
                                    request.destroy(jr)
                                    sweepers.notify(detail="unavailable", "release", p.token)
                                    overlay
                                end
                            elsif p.uri != "" then
                                sweepers.notify(detail=sweepers.stale_reason(p.token), "release", p.token)
                                overlay
                            else
                                overlay
                            end
                        if ready() and sweepers.current(p.token) then
                            log(label="sweepers", "Sweeper prepared for queue #{token}: starts #{p.offset} s after cue-in")
                            list.iter(fun (entry) -> sweepers.notify("release", fst(entry)), list.slice(offset=15, sweepers.plans()))
                            sweepers.plans := [(token, {
                                source=overlay, ready=true, offset=p.offset, jingle_id=null.get(default=0, p?.jingle_id),
                                duration=p.duck_duration, duck_gain=p.duck_gain
                            }), ...list.assoc.remove(token, sweepers.plans())]
                        elsif ready() then
                            sweepers.notify(detail=sweepers.stale_reason(p.token), "release", p.token)
                        end
                    else
                        sweepers.notify(detail="error", "release", token)
                    end
                end
            end
        catch err do
            sweepers.notify(detail="error", "release", list.assoc(default="", "sq_id", request.metadata(r)))
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

# AzuraCast's Liquidsoap 2.5 runtime has wait_for_next_song, and its fallback takes no track_sensitive.
%ifdef azuracast.wait_for_next_song
    dynamic_startup = fallback(
        id = "dynamic_startup",
        [
            dynamic,
            source.available(
                blank(id = "autodj_startup_blank", duration = 120.),
                predicate.activates({azuracast.autodj_is_loading()})
            )
        ]
    )

    s = fallback(id="autodj_fallback", [dynamic_startup, s])

    ref_dynamic = ref(dynamic)
    thread.run.recurrent(delay=0.25, { azuracast.wait_for_next_song(ref_dynamic()) })
%else
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
%endif

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
            reason = sweepers.track_reason(m)
            if null.defined(sweepers.pending()) then
                sweepers.notify(detail="skipped", "release", null.get(sweepers.pending()).token)
            end
            sweepers.notify("release", sweepers.active())
            sweepers.active := ""
            sweepers.pending := null
            sweepers.reset := true
            sweepers.duck_end := -1.
            planned = list.assoc.mem(token, sweepers.plans())
            # "none": the plugin already decided when planning.
            outcome =
                if reason != "" then reason
                elsif not sweepers.settings().enabled then "disabled"
                elsif not planned then (if azuracast.live_enabled() then "live" else "none" end)
                else
                    p = list.assoc(token, sweepers.plans())
                    if not p.ready then "unavailable"
                    elsif sweepers.current(token) then "scheduled"
                    else sweepers.stale_reason(token) end
                end
            if planned then
                p = list.assoc(token, sweepers.plans())
                sweepers.event(
                    jingle_id=if outcome == "scheduled" then p.jingle_id else 0 end,
                    offset=p.offset, "start", token, outcome, m
                )
                sweepers.duck_gain := p.duck_gain
                if outcome == "scheduled" then
                    sweepers.pending := {
                        source=p.source,
                        due=now() + p.offset,
                        duck_end=now() + p.offset + p.duration,
                        token=token
                    }
                else
                    if p.ready and reason != "" then
                        log(label="sweepers", "Sweeper skipped for queue #{token}: song spacing")
                    end
                    sweepers.notify(detail=outcome, "release", token)
                end
            else
                sweepers.event("start", token, outcome, m)
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
