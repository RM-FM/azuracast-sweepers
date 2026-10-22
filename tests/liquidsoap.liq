%include "/azuracast/azuracast.liq"
%include "/plugin/liquidsoap/sweepers.liq"

radio = sweepers.mix(blank(duration=1.))
output.dummy(fallible=true, radio)
