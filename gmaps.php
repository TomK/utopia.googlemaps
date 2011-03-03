<?php

class tabledef_GeoCache extends uTableDef {
        public function SetupFields() {
//                $this->AddField('geocache_id',ftNUMBER);
                $this->AddField('update',ftTIMESTAMP);
		$this->SetFieldProperty('update','extra','ON UPDATE CURRENT_TIMESTAMP');
                $this->SetFieldProperty('update','default','current_timestamp');
		$this->AddField('request',ftVARCHAR,500);
                $this->AddField('response',ftLONGTEXT);

                $this->SetPrimaryKey('request');
//		$this->SetIndexField('request');
        }
}

utopia::AddTemplateParser('gmapInit','GoogleMaps::GetMapInit','');
class GoogleMaps {
	private static $scriptDrawn = FALSE;
	private static function DrawScript() {
		if (self::$scriptDrawn) return;
		//<meta name="viewport" content="initial-scale=1.0, user-scalable=no" />
		utopia::AddJSFile('http://maps.google.com/maps/api/js?sensor=false');
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
		//utopia::AppendVar('script_include','
    //    $(document).ready(function () {
		//	'.$points.$fitScript.'
    //    });');
    self::$initScripts[$id] .= $points.$fitScript;
		return $points.$fitScript;
	}

	public static function GetThreshold($points,$fallback = 50) {
		if (!$points) return $fallback;
		return max($fallback,self::CalculateDistance($points['southwest'],$points['northeast']));
	}

  public static function CacheAddress($address,$pos) {
    if (!$address || !$pos) return FALSE;
    $res = sql_query("INSERT INTO tabledef_GeoCache (request,response) VALUES ('".mysql_real_escape_string($address)."','".mysql_real_escape_string(json_encode($pos))."') ON DUPLICATE KEY UPDATE");
//    if (mysql_num_rows($res))
//    if (!array_key_exists('gmaps_posCache',$_SESSION)) $_SESSION['gmaps_posCache'] = array();
//    if (array_key_exists($address,$_SESSION['gmaps_posCache'])) return FALSE;
//    mail('tom.kay@utopiasystems.co.uk','Map Cache2',"Caching $address as:\n".print_r($pos,true));
//    $_SESSION['gmaps_posCache'][$address] = $pos;
    return TRUE;
  }
  public static function GetCachedAddress($address) {
    $res = sql_query("SELECT * FROM tabledef_GeoCache WHERE `request` = '".mysql_real_escape_string($address)."' AND SUBDATE(NOW(), INTERVAL 3 DAY) < `update`");
    if (!mysql_num_rows($res)) return FALSE;
    $row = mysql_fetch_assoc($res);
    return json_decode($row['response']);
//    if (!array_key_exists('gmaps_posCache',$_SESSION)) $_SESSION['gmaps_posCache'] = array();
//    mail('tom.kay@utopiasystems.co.uk','Map Cache',"Requesting $address");
//    if (array_key_exists($address,$_SESSION['gmaps_posCache'])) return $_SESSION['gmaps_posCache'][$address];
//    return false;
  }

	public static function GetPos($address,$region=NULL,$firstOnly = true,$dropPostCode=true) {
		//$posCache =& $_SESSION['gmaps_posCache'];
		//if (!$posCache) $posCache = array();
		//if (array_key_exists($address.$region,$posCache)) return $posCache[$address.$region];
		if (empty($address)) return NULL;
		if (is_array($address)) return $address;
    $cached = self::GetCachedAddress($address.$region); if ($cached) return $cached;

		timer_start('GMaps Lookup: '.$address);
		// trim letters from end of postcode
//		$newPostCode = $dropPostCode ? '$1' : '$1 $2';
//    $address = preg_replace('/([a-z]{1,2}[0-9]{1,2})[ ]?([0-9]{1})([a-z]{2})/i', $newPostCode, $address);
//    if ($dropPostCode) {
//      $address = preg_replace('/([a-z]{1,2}[0-9]{1,2})[ ]?(([0-9]{1})([a-z]{2})?)?/i', '', $address);
//    }

		$address = urlencode($address);

    $r = $region ? '&region='.$region : ''; 
		//$out = file_get_contents('http://maps.google.com/maps/geo?q='.$address.'&oe=utf8&output=json&sensor=false&gl=uk&key='.GOOGLE_MAPS_API_KEY); // no key used here, it seems to remove all detail// Retrieve the URL contents
	//	$out = file_get_contents('http://maps.googleapis.com/maps/api/geocode/json?sensor=false&address='.$address.$r); // no key used here, it seems to remove all detail// Retrieve the URL contents

		$ch1 = curl_init();
    curl_setopt($ch1, CURLOPT_URL, 'http://maps.googleapis.com/maps/api/geocode/json?sensor=false&address='.$address.$r);
    curl_setopt($ch1, CURLOPT_HEADER, 0);
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, 1);
    $mh = curl_multi_init();
    curl_multi_add_handle($mh,$ch1);
	
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    
    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($mh) != -1) {
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
    }

    $out = curl_multi_getcontent($ch1);
    
    curl_multi_remove_handle($mh, $ch1);
    curl_multi_close($mh);
    curl_close($ch1);

		$arr = json_decode($out,true);
 //   print_r($arr);
//DebugMail('gmaps',$arr);
		if (!$arr || $arr['status'] !== 'OK') return NULL;
		
		$ret = array();
		foreach ($arr['results'] as $k => $row) {
			//if (!array_key_exists('bounds',$row['geometry'])) continue;
			$ret[] = array(
					$row['geometry']['location']['lat'],
					$row['geometry']['location']['lng'],
					array_key_exists('bounds',$row['geometry']) ? $row['geometry']['bounds'] : NULL,
					$row['formatted_address'],
					$row
				);
		}
    if (!$ret) return NULL;
		
    $first = reset($ret);
    
    self::CacheAddress($address.$region,$first);
    self::CacheAddress($first[3].$region,$first);
    //$posCache[$first[3].$region] = $first;
    return $first;
    
		if ($firstOnly && count($ret)>0) return $first;
		$posCache[$address.$region] = $ret;
		timer_end('GMaps Lookup: '.$address);
		return $ret;

		//echo 'http://maps.google.com/maps/geo?q='.$address.'&oe=utf8&output=json&sensor=false&gl=uk&key='.GOOGLE_MAPS_API_KEY;
		//print_r($arr); die();
		// Parse the returned XML file
		//echo $out;

		//$out=htmlentities($out, ENT_QUOTES);
		//$out=html_entity_decode($out, ENT_QUOTES , "utf-8");

		if ($arr['Status']['code'] != '200') return NULL;

		$widest = NULL;
		foreach ($arr['Placemark'] as $place) {
			if ($widest == NULL || ($place['AddressDetails']['Accuracy'] > $widest['AddressDetails']['Accuracy'])) {
				$widest = $place;
			}
		}

		$pos = array();
		if (array_key_exists('AdministrativeArea',$widest['AddressDetails']['Country'])) {
			if (array_key_exists('SubAdministrativeArea',$widest['AddressDetails']['Country']['AdministrativeArea'])) {
				if (array_key_exists('Locality',$widest['AddressDetails']['Country']['AdministrativeArea']['SubAdministrativeArea'])) {
					$pos[] = array_key_exists('PostalCode',$widest['AddressDetails']['Country']['AdministrativeArea']['SubAdministrativeArea']['Locality']) ? $widest['AddressDetails']['Country']['AdministrativeArea']['SubAdministrativeArea']['Locality']['PostalCode']['PostalCodeNumber'] : NULL;
					$pos[] = array_key_exists('Thoroughfare',$widest['AddressDetails']['Country']['AdministrativeArea']['SubAdministrativeArea']['Locality']) ? $widest['AddressDetails']['Country']['AdministrativeArea']['SubAdministrativeArea']['Locality']['Thoroughfare']['ThoroughfareName'] : NULL;
					$pos[] = array_key_exists('AddressLine',$widest['AddressDetails']['Country']['AdministrativeArea']['SubAdministrativeArea']['Locality']) ? $widest['AddressDetails']['Country']['AdministrativeArea']['SubAdministrativeArea']['Locality']['AddressLine'][0] : $widest['AddressDetails']['Country']['AdministrativeArea']['SubAdministrativeArea']['Locality']['LocalityName'];
				}
				$pos[] = $widest['AddressDetails']['Country']['AdministrativeArea']['SubAdministrativeArea']['SubAdministrativeAreaName'] ? $widest['AddressDetails']['Country']['AdministrativeArea']['SubAdministrativeArea']['SubAdministrativeAreaName'] : NULL;
			}
			$pos[] = $widest['AddressDetails']['Country']['AdministrativeArea']['AdministrativeAreaName'];
		}
		$pos[] = $widest['AddressDetails']['Country']['CountryName'];
		$pos = array_unique($pos);

		foreach ($pos as $k=>$v) if (!$v) unset($pos[$k]);
		$wAddy = implode(', ',$pos);

		$coords = $widest['Point']['coordinates'];
		//$coords = split(',',$coords);
		//print_r($coords);

		if (stristr($wAddy,'Limburg')!= FALSE) {
			print_r(debug_backtrace()); die();
		}

		return array($coords[1],$coords[0],$widest['AddressDetails']['Accuracy'],$wAddy,$arr);
	}

	public static function CalculateDistance($cPos,$sPos,$unit = 'M') {
		if (is_string($cPos)) $cPos = GoogleMaps::GetPos($cPos);
		if (is_string($sPos)) $sPos = GoogleMaps::GetPos($sPos);
		if (!$cPos || !$sPos) return NULL;
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
}
?>
