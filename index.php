<?php
		error_reporting(-1);
		session_start();
		ob_start();

		// Config settings
		$base = "https://www.google.com";								  // URL to redirect to
		$ckfile = '/tmp/cookieFile-' . session_id();			// Location to save captured cookies
		$mapFile = '/tmp/mapFile.csv';										// Location to store URL->token mappings
		$logFile = '/tmp/logFile.csv';										// Location to store request data

		// Set cookie domain
		$cookieDomain = str_replace("http://www.", "", $base);
		$cookieDomain = str_replace("https://www.", "", $cookieDomain);
		$cookieDomain = str_replace("www.", "", $cookieDomain);

		function rand_num() {
				return sprintf('%06X', mt_rand(0, 0xFFFFFF));
		}
    		
    function checkHostMap($oldHost) {
        global $hostMap, $myDomain;
        $parsedHost = parse_url($oldHost);
        if (array_key_exists($oldHost, $hostMap)) {
            // Have we already seen this host?
            $uniqValue = $hostMap[$oldHost];
            $newHost = $myDomain . "/" . $uniqValue;
        } elseif (array_key_exists('host', $parsedHost) && $parsedHost['host'] != $_SERVER['HTTP_HOST']) {
            // If not and it's not us, add a new entry
            while (1) {
                $uniqValue = rand_num();
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
    
		function rewriteURLs($output) {
        global $url, $hostMap, $myDomain, $base;
				$DOM = new DOMDocument;
        if ($DOM->loadHTML($output)) {
            // Extract scheme and host from current requested URL
            $parse = parse_url($url);
            $currentURL = $parse['scheme'] . "://" . $parse['host'];
            // If we have a mapping, set newURL with token
            if (array_key_exists($currentURL, $hostMap)) {
                $uriToken = $hostMap[$currentURL];
                $newURL = $myDomain . "/" . $uriToken;
            }
            // Otherwise 
            elseif ($parse['host'] != $_SERVER['HTTP_HOST']) {
                $newURL = $currentURL;
            }
            
            $attribs = array("src", "href", "action");
            $tags = $DOM->getElementsByTagName('*');
            // First rewrite relative links
            foreach ($tags as $tag) {
                foreach ( $attribs as $attrib ) {
                    $src_node = $tag->attributes->getNamedItem($attrib);
                    if($src_node)	{
                        $old_src = $tag->attributes->getNamedItem($attrib)->value;
                        // Is this a relative link and not a javascript function?
                        if (!array_key_exists('scheme', parse_url($old_src)) && !array_key_exists('host', parse_url($old_src))) {
                            if (preg_match("/^\//i", $old_src)) {
                                $new_src = $newURL . $old_src;
                            }
                            else {
                                $new_src = $newURL . "/" . $old_src;
                            }
                            $tag->setAttribute($attrib, $new_src);
                        }
                    }
                }
            }
            
            // Then rewrite all links
            foreach ($tags as $tag) {
                foreach ( $attribs as $attrib ) {
                    $src_node = $tag->attributes->getNamedItem($attrib);
                    if($src_node)	{
                        $old_src = $tag->attributes->getNamedItem($attrib)->value;
                        $parsedAttrib = parse_url($old_src);
                        if (array_key_exists('host', $parsedAttrib)) {
                            // Don't rewrite if it's us
                            if ($parsedAttrib['host'] != $_SERVER['HTTP_HOST']) {
                                if (array_key_exists('scheme', $parsedAttrib)) {
                                    $oldHost = $parsedAttrib['scheme'] . "://" . $parsedAttrib['host'];
                                }
                                else {
                                    $oldHost = $parsedAttrib['host'];
                                }
                                
                                $newHost = checkHostMap($oldHost);
                                $newURL = str_replace($oldHost, $newHost, $old_src);
                                $tag->setAttribute($attrib, $newURL);   
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
						$key = array_search($uriToken, $hostMap);
						$path = substr($_SERVER['REQUEST_URI'], 7);
						$url = $key . "/" . $path;
				} else {
						$url = $base . $_SERVER['REQUEST_URI'];
				}
		} else {
				// If not, return the base URL
				$url = $base . $_SERVER['REQUEST_URI'];
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
				if (array_key_exists('CONTENT_TYPE', $_SERVER ) && strpos($_SERVER['CONTENT_TYPE'], "multipart/form-data") === 0) {
						$fileName = $_FILES['upfile']['name'];
						$fileContent = file_get_contents($_FILES['upfile']['tmp_name']);
						curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
				}
				else {
						curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents("php://input"));
				}
		}
		// Set referrer
		if (array_key_exists('HTTP_REFERER', $_SERVER)) {
				curl_setopt($ch, CURLOPT_REFERER, $_SERVER['HTTP_REFERER']);
		}

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLINFO_HEADER_OUT, true);
		curl_setopt($ch, CURLOPT_COOKIEJAR,  $ckfile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $ckfile);
		$curlResponse = curl_exec($ch);

		// Check that a connection was made
		if (curl_error($ch)) {
				print curl_error($ch);
		}
    else {
				// Clean duplicate responses
				$curlResponse = str_replace("HTTP/1.1 100 Continue\r\n\r\n", "", $curlResponse);
				$ar = explode("\r\n\r\n", $curlResponse, 2);
				$header = $ar[0];
				$body = $ar[1];

				// Repeat headers for client response
				$arHeader = explode(chr(10), $header);
				foreach ($arHeader as $key => $value) {
						if (!preg_match("/^Transfer-Encoding/", $value)) {
								$value = str_replace($base, $myDomain, $value); // header rewrite if needed
								header(trim($value));
						}
						if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 302) {
								if (preg_match("/^Location/", $value)) {
                    $parsedAttrib = parse_url(substr($value, 10));
                    $oldHost = $parsedAttrib['scheme'] . "://" . $parsedAttrib['host'];
                    $newHost = checkHostMap($oldHost);
                    $value = str_replace($oldHost, $newHost, $value);
                    header(trim($value));
								}
						}
            if (preg_match("/text\/html/i", $value)) {
                header(trim($value));
                $body = rewriteURLs($body);
						}
				}
        
        $results = $body;
		}
		curl_close($ch);

		// Update url mapping file
		$fMap = fopen($mapFile, 'w');
		foreach ($hostMap as $key => $value) {
				$row = $key . "," . $value;
				$row = explode(',', $row);
				fputcsv($fMap, $row);
		}
		fclose($fMap);

		if (!empty($results)) {
				print $results;
		}
?>