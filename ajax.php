<?php
header('Content-Type: application/json; charset=utf-8');

$thumb = $title = '';
$iArray = array(18 => "MP4 360p", 22 => "MP4 720p", 36 => "3GP 240p", 43 => "WebM 360p");

function getID($url) {
	$pattern = '%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i';
	if (preg_match($pattern, $url, $matches)) return $matches[1];
	else exit(json_encode(array(
	'Code' => '404', 
	'Status' => 'Error', 
	'Message' => 'Video ID Bulunamadı.',
	'HTMLGui' => '
	<div class="col-md-6">
		<div class="alert alert-danger">
			<button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
			<i class="fa fa-exclamation fa-fw"></i>Video ID Bulunamadı.
		</div>
	</div>'
	)));
}
function cURL($url) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_HEADER, FALSE);
	curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
	$response = curl_exec($ch);
	curl_close($ch);
	return $response;
}
function getFunction($html) {
	if (preg_match('/signature",([a-zA-Z0-9]+)\(/', $html, $matches)) {
		$fname = $matches[1];
		if (preg_match("/{$fname}=function\([a-z]+\){(.*?)}/", $html, $matches)) {
			$t = $matches[1];
			if (preg_match_all('/([a-z0-9]{2})\.([a-z0-9]{2})\([^,]+,(\d+)\)/i', $t, $matches) != false) {
				$fArray = $matches[2];
				preg_match_all('/('.implode('|', $fArray).'):function(.*?)\}/m', $html, $matches2, PREG_SET_ORDER);
				$mArray = array();
				foreach($matches2 as $j) {
					if (strpos($j[2], "splice") !== false) $mArray[$j[1]] = "splice";
					else if (strpos($j[2], "a.length") !== false) $mArray[$j[1]] = "swap";
					else if (strpos($j[2], "reverse") !== false) $mArray[$j[1]] = "reverse";
				}
				$method = array();
				if (is_array($matches[2])) {
					foreach($matches[2] as $index => $name) {
						$method[] = array($mArray[$name],$matches[3][$index]);
					}
				}
				return $method;
			}
		}
	}
	return false;
}
function getPlayer($html) {
	if (preg_match('@<script\s*src="([^"]+player[^"]+js)@', $html, $matches)) return getFunction(cURL("https://www.youtube.com".$matches[1]));
	return false;
}
function deCipher($signature, $method) {
	foreach($method as $j) {
		$c = $j[0];
		$v = $j[1];
		if ($c == 'swap') {
			$t = $signature[0];
			$signature[0] = $signature[$v % strlen($signature)];
			$signature[$v] = $t;
		}
		else if ($c == 'splice') $signature = substr($signature, $v);
		else if ($c == 'reverse') $signature = strrev($signature);
	}
	return trim($signature);
}
function getDownload($id) {
	global $iArray, $title, $thumb;
	$method = $result = array();
	$html = cURL('https://www.youtube.com/watch?v='.$id);
	if (strpos($html, 'player-age-gate-content') !== false) return false;
	$thumb = 'https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg';
	if (preg_match("/<title>(.+?)<\/title>/is", $html, $matches)) $title = str_replace(' - YouTube', '', $matches[1]);
	if (preg_match('@url_encoded_fmt_stream_map["\']:\s*["\']([^"\'\s]*)@', $html, $matches)) {
		$streams = explode(",", $matches[1]);
		foreach($streams as $stream) {
			parse_str(str_replace('\u0026', '&', $stream), $data);
			$url = $data['url'];
			if (isset($data['s'])) {
				if (count($method) == 0) $method = (array)getPlayer($html);
				$url .= '&signature='.deCipher($data['s'], $method);
			}
			else if (isset($data['sig'])) $url .= '&signature='.$data['sig'];
			else if (isset($data['signature'])) $url .= '&signature='.$data['signature'];
			$itag = $data['itag'];
			$format = isset($iArray[$itag]) ? $iArray[$itag] : '-';
			if ($itag != 17) $result[$itag] = array('url' => $url, 'format' => $format, 'sig' => deCipher($data['s'], $method));
		}
	}
	return $result;
}

if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || !isset($_POST['action']) || !isset($_POST['video_url'])) {
	exit(json_encode(array('Code' => '403', 'Status' => 'Error', 'Message' => 'Erişim Engellendi.')));
}

$result = getDownload(getID($_POST['video_url']));

if (count($result) < 1) exit(json_encode(array(
	'Code' => '405', 
	'Status' => 'Error', 
	'Message' => 'Ooops, İndirme Linki Bulunamadı.',
	'HTMLGui' => '
	<div class="col-md-6">
		<div class="alert alert-danger">
			<button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
			<i class="fa fa-exclamation fa-fw"></i>Ooops, İndirme Linki Bulunamadı.
		</div>
	</div>'
	)));
else {
	$HTMLGui = '<div class="col-md-6">';
	$HTMLGui .= '<div class="btn-dwnld text-center">';
	$HTMLGui .= '<p class="video-title">'.$title.'</p>';
	$HTMLGui .= '<img class="video-thumb" src="'.$thumb.'" alt="Thumb" width="300" height="200" /><br/>';
	foreach ($result as $r) {
		$HTMLGui .= '<a class="btn btn-primary btn-sm" href="'.$r['url'].'" download>'.$r['format'].'<a/>';
	}
	$HTMLGui .= '</div>';
	$HTMLGui .= '</div>';
	exit(json_encode(array(
		'Code' => '200', 
		'Status' => 'Success',
		'Message' => 'İndirme Linkleri Hazırlandı.',
		'HTMLGui' => $HTMLGui
	)));
}
?>
