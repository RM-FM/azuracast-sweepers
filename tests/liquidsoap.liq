%include "/azuracast/azuracast.liq"
%include "/plugin/liquidsoap/dropins.liq"

radio = dropins.mix(blank(duration=1.))
output.dummy(fallible=true, radio)
