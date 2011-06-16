<?php

class tabledef_GeoCache extends uTableDef {
        public function SetupFields() {
                $this->AddField('geocache_id',ftNUMBER);
                $this->AddField('update',ftTIMESTAMP);
		$this->SetFieldProperty('update','extra','ON UPDATE CURRENT_TIMESTAMP');
                $this->SetFieldProperty('update','default','current_timestamp');
		$this->AddField('request',ftLONGTEXT);
                $this->AddField('response',ftLONGTEXT);

                $this->SetPrimaryKey('geocache_id');
        }
}

utopia::AddTemplateParser('gmapInit','GoogleMaps::GetMapInit','');
class GoogleMaps {
	private static $scriptDrawn = FALSE;
	private static function DrawScript() {
		if (self::$scriptDrawn) return;
		//<meta name="viewport" content="initial-scale=1.0, user-scalable=no" />
		modOpts::AddOption('uJavascript','gmaps_version','Google Maps','',itTEXT);
		$gmapsVer = modOpts::GetOption('uJavascript','gmaps_version');
		if ($gmapsVer !== '0') {
			$v = $gmapsVer === '' ? '' : 'v='.$gmapsVer.'&';
			utopia::AddJSFile('http://maps.google.com/maps/api/js?'.$v.'sensor=false');
		}
		utopia::AddJSFile(utopia::GetRelativePath(dirname(__FILE__).'/gmaps.js'));
		utopia::AppendVar('script_include','{gmapInit}');
		self::$scriptDrawn = true;
	}
	
	static $initScripts = array();
	public static function GetMapInit() {
		$out = '$(document).ready(function () {'.PHP_EOL;
		foreach (self::$initScripts as $id => $script)
			$out .= $script;
    $out .= PHP_EOL.'});'.PHP_EOL;
    $out = JSMin::minify($out);
		return $out;
	}

	public static function DrawMap($id,$width,$height,$center=NULL,$bounds=NULL,$points=array(),$style=array()) {
		if (empty($center)) {
			$center=array(0,0);
		}
		self::DrawScript();
		if (is_array($bounds) && array_key_exists(0,$bounds) && array_key_exists(1,$bounds)) $bounds = 'bounds: new google.maps.LatLngBounds(new google.maps.LatLng('.$bounds['southwest']['lat'].','.$bounds['southwest']['lng'].'),new google.maps.LatLng('.$bounds['northeast']['lat'].','.$bounds['southwest']['lng'].')),'."\n";
		//if (!array_key_exists($id,self::$initScripts)) self::$initScripts[$id] = '';
		self::$initScripts[$id] = <<<FIN
    markers["$id"] = [];
		if (!document.getElementById("$id")) { alert("Cannot initialise map.  Placeholder not found."); return; }
		var center = new google.maps.LatLng({$center[0]},{$center[1]})
		var myOptions = {
			{$bounds}scrollwheel: true,
			center: center,
			mapTypeId: google.maps.MapTypeId.ROADMAP,
			mapTypeControlOptions: {style: google.maps.MapTypeControlStyle.DROPDOWN_MENU}
		};
		if (typeof(maps["$id"]) == "undefined") maps["$id"] = new google.maps.Map(document.getElementById("$id"),myOptions);
FIN;
		self::AddMarkers($id,$points);

		$style['width'] = $width;
		$style['height'] = $height;

		$newStyle=array();
		foreach ($style as $key => $val) {
			$newStyle[] = "$key:$val";
		}
		$style = join(';',$newStyle);

		return '<div id="'.$id.'" style="'.$style.'"></div>';
	}

	public static function AddMarkers($id, $points, $fitBounds=true) {
		//if (is_string($center)) $center = self::GetPos($center);
		//if ($points===NULL) $points = $center;
		if (is_string($points)) $points = array($points);
		$newpoints = array();
		//if (empty($center)) {
		//$center=array(0,0);
		//}
		self::DrawScript();

		if (!is_array($points) || count($points) <= 0) return;

		foreach ($points as $point) {
			// points are:   Lat,  Lng,  Title,  html, url, image, zindex
			if (!$point) continue;
			if (is_string($point)) $point = self::GetPos($point); // lat lng bounds address point
			if (empty($point[0]) || empty($point[1])) continue;

			$html = array_key_exists(3,$point) && is_array($point[3]) ? addslashes($point[3]['html']) : NULL;
			$url = array_key_exists(4,$point) && !is_array($point[4]) ? addslashes($point[4]) : NULL;
			//$tip = array_key_exists(2,$point) && !is_array($point[2]) ? addslashes($point[2]) : NULL;
			$title = '';
			$tip = '';
			if (array_key_exists(2,$point) && !is_array($point[2])) {
				if (array_key_exists(2,$point) && $point[2] == strip_tags($point[2]) && !empty($url)) {
					$title = addslashes($point[2]);
					$tip = addslashes($point[3]);
				} elseif (array_key_exists(2,$point)) {
					$html = addslashes($point[2]);
				}
			}
			$image = array_key_exists(5,$point) ? $point[5] : NULL;
			$zIndex = array_key_exists(6,$point) ? $point[6] : NULL;

			$clickable = !empty($tip) || !empty($html) || !empty($url) ? true : false;

			$opts = ',{clickable:'.$clickable.', title:"'.$title.'"}';

			$navigate = !empty($url) ? '		google.maps.event.addListener(marker, "click", function() {
				window.location.href = "'.htmlspecialchars_decode($url).'";
				});':'';
			$zIndexJS = $zIndex ? ",\n			zIndex: $zIndex":'';
			$clickableJS = !$clickable ? ",\n			clickable:false":'';
			$newpoints[] ='		var marker = new google.maps.Marker({
			position: new google.maps.LatLng('.$point[0].','.$point[1].'),
			map: maps["'.$id.'"],
			icon: "'.$image.'",
			title:"'.$title.'"'.$clickableJS.$zIndexJS.'
			});markers["'.$id.'"].push(marker);'."\n".$navigate;
		}
		$points = "\n".implode("\n",$newpoints);
		$fitScript = $fitBounds ? 'FitMapToMarkers("'.$id.'");'."\n" : '';
		self::$initScripts[$id] .= $points.$fitScript;
		return $points.$fitScript;
	}

	public static function GetThreshold($points,$fallback = 50) {
		if (!$points) return $fallback;
		return max($fallback,self::CalculateDistance($points['southwest'],$points['northeast']));
	}

	public static function CacheAddress($address,$pos) {
		if (!$address || !$pos) return FALSE;
		$cache = self::GetCachedAddress($address,0);
		if (!$cache)
			$res = sql_query("INSERT INTO tabledef_GeoCache (`request`,`response`) VALUES ('".mysql_real_escape_string($address)."','".mysql_real_escape_string(json_encode($pos))."')");
		else
			$res = sql_query("UPDATE tabledef_GeoCache SET `response` = '".mysql_real_escape_string(json_encode($pos))."' WHERE `request` LIKE '".mysql_real_escape_string($address)."'");

		return TRUE;
	}
	public static function GetCachedAddress($address,$expires=3) {
		$expires = $expires && is_numeric($expires) ? ' AND SUBDATE(NOW(), INTERVAL '.$expires.' DAY) < `update`' : '';
		$res = sql_query("SELECT * FROM tabledef_GeoCache WHERE `request` LIKE '".mysql_real_escape_string($address)."'".$expires);
		if (!$res || !mysql_num_rows($res)) return FALSE;
		$row = mysql_fetch_assoc($res);
		return json_decode($row['response'],true);
	}

	public static function GetPos($address,$region=true,$firstOnly = true,$dropPostCode=true) {
		if (is_array($address)) return $address;
		$address = trim($address);
		if ($region === TRUE) {
			$region = self::GeoIP($_SERVER['REMOTE_ADDR']);
		}
		if (empty($address) && !empty($region)) $address = $region;
		if (empty($address)) return NULL;
		if ($region == $address) $region = '';
		if (!is_string($region)) $region = '';

		$cached = self::GetCachedAddress($address.$region,0); if ($cached !== FALSE) return $cached;
		
		timer_start('GMaps Lookup: '.$address);
		// trim letters from end of postcode

		$r = $region ? '&region='.$region : ''; 

		$out = curl_get_contents('http://maps.googleapis.com/maps/api/geocode/json?sensor=false&address='.urlencode($address).$r);

		$arr = json_decode($out,true);
		if (!$arr) return NULL;

		switch ($arr['status']) {
			case 'OK': break;
			case 'ZERO_RESULTS':
				self::CacheAddress($address.$region,'');
				return FALSE;
			default:
				DebugMail('GMaps request not OK',$address."\n\n".print_r($arr,true));
				return NULL;
				break;
		}
		
		$row = reset($arr['results']);
		if (!$row) return NULL;

		// locality, administrative_area_level_2, administrative_area_level_1
		$newFormattedAddress = array();
		if ($row && isset($row['address_components'])) {
			foreach($row['address_components'] as $c) {
				if (/*!isset($newFormattedAddress[0]) &&*/ array_search('locality',$c['types']) !== FALSE)
					$newFormattedAddress[0] = $c['long_name'];
                                if (/*!isset($newFormattedAddress[1]) &&*/ array_search('administrative_area_level_2',$c['types']) !== FALSE)
                                        $newFormattedAddress[1] = $c['long_name'];
                                if (/*!isset($newFormattedAddress[2]) &&*/ array_search('administrative_area_level_1',$c['types']) !== FALSE)
                                        $newFormattedAddress[2] = $c['long_name'];
			}
		}
		if (count($newFormattedAddress) >= 2) $row['formatted_address'] = implode(', ',$newFormattedAddress);

		$ret = array(
			$row['geometry']['location']['lat'],
			$row['geometry']['location']['lng'],
			isset($row['geometry']['bounds']) ? $row['geometry']['bounds'] : $row['geometry']['viewport'],
			$row['formatted_address'],
			$row
		);

		self::CacheAddress($address.$region,$ret);
		self::CacheAddress($ret[3].$region,$ret);
		return $ret;
	}

	public static function CalculateDistance($cPos,$sPos,$unit = 'M') {
		if (!$cPos || !$sPos) return NULL;
		if (is_string($cPos)) $cPos = GoogleMaps::GetPos($cPos);
		if (is_string($sPos)) $sPos = GoogleMaps::GetPos($sPos);
		list($lat1,$lon1) = array_values($cPos);
		list($lat2,$lon2) = array_values($sPos);

		if (!$lat1 || !$lon1 || !$lat2 || !$lon2) return NULL;

		$theta = $lon1 - $lon2;
		$dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
		$dist = acos($dist);
		$dist = rad2deg($dist);
		$miles = $dist * 60 * 1.1515;

		$unit = strtoupper($unit);
		if ($unit == "K") {
			return ($miles * 1.609344);
		} else if ($unit == "N") {
			return ($miles * 0.8684);
		}

		return $miles;
	}

	public static function NewPoint($pos,$icon=NULL) {
		return array('pos'=>$pos,'icon'=>$icon);
	}

	public static function GeoIP($ip) {
		$cached = self::GetCachedAddress($ip);
		if ($cached !== FALSE) return $cached;

		$region = curl_get_contents('http://geoip.wtanaka.com/cc/'.$ip);
		//DebugMail('GeoIP Lookup',$region ? $region : 'Not Found');

		self::CacheAddress($ip,$region);
		return $region;
	}
}
?>
