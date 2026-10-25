settings.init.allow_root := true
%include "/azuracast/azuracast.liq"
%include "/plugin/liquidsoap/sweepers.liq"

def test_configure(~enabled=true, ~minimum_songs=1, ~after_jingle=-1, ~after_sweeper=-1,
                   ~count_jingle_mode=true, ~jingle_playlists=[7, 8]) =
    songs_after_jingle = if after_jingle < 0 then minimum_songs else after_jingle end
    songs_after_sweeper = if after_sweeper < 0 then minimum_songs else after_sweeper end
    sweepers.configure({
        enabled=enabled, songs_after_jingle=songs_after_jingle, songs_after_sweeper=songs_after_sweeper,
        count_jingle_mode=count_jingle_mode, jingle_playlists=jingle_playlists,
        revision=json.stringify({enabled=enabled, songs_after_jingle=songs_after_jingle,
            songs_after_sweeper=songs_after_sweeper, count_jingle_mode=count_jingle_mode,
            jingle_playlists=jingle_playlists})
    })
end

test_configure()

def test_track(~playlist_id=1, ~jingle=false, queue_id) =
    sweepers.track_allowed([
        ("sq_id", "#{queue_id}"), ("media_id", "1"),
        ("playlist_id", "#{playlist_id}"), ("jingle_mode", if jingle then "true" else "false" end)
    ])
end

def expect(value, message) =
    if not value then error.raise(error.failure, message) end
end

expect(not test_track(1, jingle=true), "Jingle Mode track must reset spacing")
expect(not test_track(2), "First song after jingle must remain clear")
expect(test_track(3), "One intervening song permits sweeper on next song")
sweepers.notify("played", "3")
expect(not test_track(4), "Song containing previous sweeper is not an intervening song")
sweepers.notify("release", "4")
expect(test_track(5), "Released prefetch does not reset spacing")
expect(not test_track(6, playlist_id=7), "Selected playlist resets spacing")
expect(not test_track(7), "Selected playlist blocks the immediately following song")
expect(test_track(8), "Other playlists count as songs")
expect(not test_track(9, playlist_id=8), "Every selected playlist counts")
test_configure(count_jingle_mode=false)
expect(not test_track(10, jingle=true), "Disabled Jingle Mode is treated as first regular song")
expect(test_track(11, jingle=true), "Disabled Jingle Mode does not reset spacing")
test_configure(count_jingle_mode=false, minimum_songs=2)
expect(not test_track(12, playlist_id=7), "Playlist recognition works without Jingle Mode")
expect(not test_track(13), "Two-song gap: first song blocked")
expect(not test_track(14), "Two-song gap: second song blocked")
expect(test_track(15), "Two-song gap: third song allowed")
expect(not sweepers.track_allowed([]), "Unknown source cannot receive sweeper")
expect(not test_track(16), "Unknown source resets spacing conservatively")
test_configure(minimum_songs=0)
expect(test_track(17), "Zero disables song spacing")
expect(not test_track(18, playlist_id=7), "Never overlay a recognized jingle")
test_configure()
expect(not test_track(19), "First clear song after jingle")
expect(test_track(20), "Second clear song")
expect(test_track(21), "Third clear song")
test_configure(minimum_songs=3)
expect(test_track(22), "Raising the gap retains all already played clear songs")
sweepers.notify("played", "22")
expect(not test_track(23), "New three-song gap: first song")
expect(not test_track(24), "New three-song gap: second song")
expect(not test_track(25), "New three-song gap: third song")
expect(test_track(26), "New three-song gap: fourth song allowed")
test_configure(minimum_songs=1)
expect(test_track(27), "Lowering the gap preserves spacing history")
test_configure(jingle_playlists=[9])
expect(not test_track(28, playlist_id=9), "Live playlist change takes effect")
expect(not test_track(29, playlist_id=7), "Former jingle playlist now counts as first song")
expect(test_track(30, playlist_id=7), "Former jingle playlist no longer resets spacing")

test_configure(after_jingle=3, after_sweeper=2)
expect(not test_track(31, jingle=true), "Separate gaps: jingle")
expect(not test_track(32), "After jingle 3: first song blocked")
expect(not test_track(33), "After jingle 3: second song blocked")
expect(not test_track(34), "After jingle 3: third song blocked")
expect(test_track(35), "After jingle 3: fourth song allowed")
sweepers.notify("played", "35")
expect(not test_track(36), "After sweeper 2: first song blocked")
expect(not test_track(37), "After sweeper 2: second song blocked")
expect(test_track(38), "After sweeper 2: third song allowed without restarting the jingle gap")
test_configure(after_jingle=0, after_sweeper=1)
sweepers.notify("played", "38")
expect(not test_track(39), "After sweeper 1 applies with no jingle gap")
expect(test_track(40), "One clear song after a sweeper")
test_configure(after_jingle=2, after_sweeper=0)
expect(not test_track(41, playlist_id=7), "Jingle with only an after-jingle gap")
expect(not test_track(42), "After jingle 2: first song blocked")
expect(not test_track(43), "After jingle 2: second song blocked")
expect(test_track(44), "After jingle 2: third song allowed")
sweepers.notify("played", "44")
expect(test_track(45), "A sweeper does not start the jingle gap")
test_configure(after_jingle=0, after_sweeper=2)
sweepers.notify("played", "45")
expect(not test_track(46, jingle=true), "A jingle is never overlaid")
expect(not test_track(47), "A jingle is not a song for the sweeper gap")
expect(not test_track(48), "Sweeper gap still counts only songs")
expect(test_track(49), "Two clear songs after the sweeper")

current = sweepers.settings().revision
sweepers.blocked := ["51"]
expect(not sweepers.current("51", current), "A queued jingle cancels that song's plan")
expect(sweepers.current("52", current), "Other plans remain valid")
blocked_overlay = source.fail()
sweepers.plans := [("51", {
    source=blocked_overlay, ready=true, offset=1., duration=1., duck_gain=0.5, revision=current
})]
sweepers.pending := {source=blocked_overlay, due=1., duck_end=2., token="51", revision=current}
ignore(sweepers.next_overlay())
expect(not null.defined(sweepers.pending()), "A newly queued jingle cancels a pending overlay")
expect(sweepers.plans() == [], "A blocked reservation is released")
sweepers.blocked := []

revision = sweepers.settings().revision
overlay = source.fail()
sweepers.plans := [("31", {
    source=overlay, ready=true, offset=1., duration=1., duck_gain=0.5, revision=revision
})]
sweepers.pending := {source=overlay, due=1., duck_end=2., token="31", revision=revision}
test_configure(enabled=false)
ignore(sweepers.next_overlay())
expect(not null.defined(sweepers.pending()), "Disabling discards a not-yet-started overlay")
expect(sweepers.plans() == [], "Disabling frees prefetched reservations")
expect(list.exists(fun (e) -> e.event == "release" and e.token == "31", sweepers.events()), "Cancelled plan reported as release")
sweepers.active := "playing"
sweepers.duck_end := 10.
sweepers.reset := false
expect(not null.defined(sweepers.next_overlay()), "Disabling does not interrupt an already audible sweeper")
expect(sweepers.duck_end() == 10., "Active ducking remains tied to its original handover")
test_configure(enabled=true, minimum_songs=2)
expect(sweepers.current("31", sweepers.settings().revision), "Re-enable without rebuilding the source")
expect(not sweepers.current("31", revision), "Plans from an older configuration remain invalid")
print("Song spacing checks passed.")

thread.run(delay=0.1, {shutdown()})
