%include "/azuracast/azuracast.liq"
%include "/plugin/liquidsoap/sweepers.liq"
sweepers.load(version="check", {enabled=false, revision="check", songs_after_jingle=0, songs_after_sweeper=0, count_jingle_mode=true, jingle_playlists=[]})

radio = fallback([blank(duration=1.)])
radio = sweepers.enable_autodj(radio)
radio = sweepers.mix(azuracast.apply_crossfade(radio))
output.dummy(fallible=true, radio)
