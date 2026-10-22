def sweepers.sidechain(music, key) =
  main = ffmpeg.raw.encode.audio(%ffmpeg(%audio.raw), music)
  control = ffmpeg.raw.encode.audio(%ffmpeg(%audio.raw), key)
  ffmpeg.filter.create(fun (graph) -> begin
    main = ffmpeg.filter.audio.input(graph, source.tracks(main).audio)
    control = ffmpeg.filter.audio.input(pass_metadata=false, graph, source.tracks(control).audio)
    let (branches, _) = ffmpeg.filter.asplit(outputs=2, graph, main)
    let (keys, _) = ffmpeg.filter.asplit(outputs=2, graph, control)
    dry = ffmpeg.filter.audio.output(pass_metadata=false, graph, list.nth(branches, 0))
    wet = ffmpeg.filter.sidechaincompress(
      threshold=0.01, ratio=8., attack=150., release=750.,
      knee=2., link=1, detection=1, makeup=1.,
      graph, list.nth(branches, 1), list.nth(keys, 0)
    )
    wet = ffmpeg.filter.audio.output(pass_metadata=false, graph, wet)
    audible = ffmpeg.filter.audio.output(pass_metadata=false, graph, list.nth(keys, 1))
    def decoded(raw_audio) =
      ffmpeg.raw.decode.audio(source({
        audio=raw_audio, metadata=track.metadata(raw_audio), track_marks=track.track_marks(raw_audio)
      }))
    end
    (decoded(dry), decoded(wet), decoded(audible))
  end)
end
