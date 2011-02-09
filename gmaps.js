var maps = [];
var markers = [];
function ClearAllMarkers(mapId) {
	if (!maps[mapId]) return;
	for (var _marker in markers[mapId]) {
		ClearMapMarker(mapId,_marker);
	}
}
function ClearMapMarker(mapId,markerId,resize) {
	if (!maps[mapId]) return;
	if (typeof(markers) == "undefined") return;
	if (!markers[mapId][markerId]) return;
	markers[mapId][markerId].setMap(null);
	delete markers[mapId][markerId];
	if (resize) FitMapToMarkers(mapId);
}
function FitMapToMarkers(mapId) {
	if (!maps[mapId]) return;

	var bounds = new google.maps.LatLngBounds();
	for (var _marker in markers[mapId]) {
		bounds.extend(markers[mapId][_marker].position);
	}
	maps[mapId].fitBounds(bounds);
	if (markers[mapId].length == 1) maps[mapId].setZoom(12);
}
function CheckResizeMaps() {
	for (var _map in maps) {
		google.maps.event.trigger(maps[_map], "resize");
		FitMapToMarkers(_map);
	}
}
var geocoder = null;
function AddMapMarker(mapId,id,address) {
	if (typeof(maps[mapId]) == "undefined") return;
	if (geocoder == null) geocoder = new google.maps.Geocoder();
	//var address = document.getElementById("address").value;
	geocoder.geocode( { address: address}, function(results, status) {
		if (status == google.maps.GeocoderStatus.OK && results.length) {
			// You should always check that a result was returned, as it is
			// possible to return an empty results object.
			if (status != google.maps.GeocoderStatus.ZERO_RESULTS) {
				ClearMapMarker(mapId,id);
				markers[mapId][id] = new google.maps.Marker({
					position: results[0].geometry.location,
					map: maps[mapId],
					clickable:false,
					title: address
				});
				FitMapToMarkers(mapId);
			}
		} else {
			$(":input[name^=locs][value=\'+address+\']").css("border","#f00 solid 2px");
			ClearMapMarker(mapId,id);
			//alert("Geocode was unsuccessful due to: " + status);
		}
	});
}
$(".ui-tabs").live("tabsshow",function() { setTimeout(function() {CheckResizeMaps();},200); });