settings.init.allow_root := true
%include "/azuracast/azuracast.liq"
%include "/plugin/liquidsoap/sweepers.liq"

sweepers.minimum_songs := 1
sweepers.jingle_playlists := [7, 8]

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
sweepers.count_jingle_mode := false
expect(not test_track(10, jingle=true), "Disabled Jingle Mode is treated as first regular song")
expect(test_track(11, jingle=true), "Disabled Jingle Mode does not reset spacing")
sweepers.minimum_songs := 2
expect(not test_track(12, playlist_id=7), "Playlist recognition works without Jingle Mode")
expect(not test_track(13), "Two-song gap: first song blocked")
expect(not test_track(14), "Two-song gap: second song blocked")
expect(test_track(15), "Two-song gap: third song allowed")
expect(not sweepers.track_allowed([]), "Unknown source cannot receive sweeper")
expect(not test_track(16), "Unknown source resets spacing conservatively")
sweepers.minimum_songs := 0
expect(test_track(17), "Zero disables song spacing")
expect(not test_track(18, playlist_id=7), "Never overlay a recognized jingle")
print("Song spacing checks passed.")

thread.run(delay=0.1, {shutdown()})
