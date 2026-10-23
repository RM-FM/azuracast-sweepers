def sweepers.sidechain(~blend, music, key) =
    control = compress(
        attack=150., release=750., threshold=-40., ratio=8.,
        knee=2., window=0.02, wet=0., key
    )
    def gain() =
        amount = blend()
        1. - amount + amount * lin_of_dB(control.gain())
    end
    music = amplify(override=null, gain, music)
    add(normalize=false, [music, source.drop.metadata(control)])
end
