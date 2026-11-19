<?php
/* μlogger
 *
 * Copyright(C) 2017 Bartek Fabiszewski (www.fabiszewski.net)
 *
 * This is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Exit with error status and message
 *
 * @param string $message Message
 */
function exitWithError($message) {
  $response = [];
  $response['error'] = true;
  $response['message'] = $message;
  header('Content-Type: application/json');
  echo json_encode($response);
  exit();
}

/**
 * Exit with success status
 *
 * @param array $params Optional params
 * @return void
 */
function exitWithSuccess($params = []) {
  $response = [];
  $response['error'] = false;
  header('Content-Type: application/json');
  echo json_encode(array_merge($response, $params));
  exit();
}

require_once(dirname(__DIR__) . "/helpers/auth.php");
require_once(ROOT_DIR . "/helpers/config.php");
require_once(ROOT_DIR . "/helpers/track.php");
require_once(ROOT_DIR . "/helpers/position.php");
require_once(ROOT_DIR . "/helpers/upload.php");

$action = uUtils::postString('action');

$auth = new uAuth();
if ($action !== "auth" && !$auth->isAuthenticated()) {
  $auth->sendUnauthorizedHeader();
  exitWithError("Unauthorized");
}

$config = uConfig::getInstance();

switch ($action) {
  // action: authorize
  case "auth":
    $login = uUtils::postString('user');
    $pass = uUtils::postPass('pass');
    if ($auth->checkLogin($login, $pass)) {
      exitWithSuccess();
    } else {
      $auth->sendUnauthorizedHeader();
      exitWithError("Unauthorized");
    }
    break;

  // action: adduser (currently unused)
  case "adduser":
    if (!$auth->user->isAdmin) {
      exitWithError("Not allowed");
    }
    $login = uUtils::postString('login');
    $pass = uUtils::postPass('password');
    if (empty($login) || empty($pass)) {
      exitWithError("Empty login or password");
    }
    $newId = uUser::add($login, $pass);
    if ($newId === false) {
      exitWithError("Server error");
    }
    exitWithSuccess(['userid' => $newId]);
    break;

  // action: addtrack
  case "addtrack":
    $trackName = uUtils::postString('track');
    if (empty($trackName)) {
      exitWithError("Missing required parameter");
    }
    require_once(ROOT_DIR . "/helpers/track.php");
    $trackId = uTrack::add($auth->user->id, $trackName);
    if ($trackId === false) {
      exitWithError("Server error");
    }
    // return track id
    exitWithSuccess(['trackid' => $trackId]);
    break;

  // action: addposition
  case "addpos":
    $lat = uUtils::postFloat('lat');
    $lon = uUtils::postFloat('lon');
    $timestamp = uUtils::postInt('time');
    $altitude = uUtils::postFloat('altitude');
    $speed = uUtils::postFloat('speed');
    $bearing = uUtils::postFloat('bearing');
    $accuracy = uUtils::postInt('accuracy');
    $provider = uUtils::postString('provider');
    $comment = uUtils::postString('comment');
    $imageMeta = uUtils::requestFile('image');
    $trackId = uUtils::postInt('trackid');

    if (!is_float($lat) || !is_float($lon) || !is_int($timestamp) || !is_int($trackId)) {
      exitWithError("Missing required parameter");
    }

    // Auto-split track if configured
    if ($config->autoSplitTime > 0 && $config->autoSplitDistance > 0) {
      $lastPosition = uPosition::getLastForTrack($trackId);
      if ($lastPosition->isValid) {
        // Create a temporary position object for the new point to calculate distance/time
        $newPos = new uPosition();
        $newPos->latitude = $lat;
        $newPos->longitude = $lon;
        $newPos->timestamp = $timestamp;

        $distance = $newPos->distanceTo($lastPosition);
        $timeDiff = $newPos->secondsTo($lastPosition);

        if ($distance <= $config->autoSplitDistance && $timeDiff >= $config->autoSplitTime) {
          // Split the track: archive current track, keep same trackId for new points
          $db = uDb::getInstance();
          try {
            $db->beginTransaction();

            // Get first position of current track for naming the archived track
            $firstPosition = uPosition::getFirst($trackId);
            $archiveName = "";
            if ($firstPosition->isValid) {
              $archiveName = date('c', $firstPosition->timestamp); // ISO8601
            } else {
              $archiveName = date('c', $timestamp);
            }

            // Create new archived track with the timestamp name
            $archiveTrackId = uTrack::add($auth->user->id, $archiveName);
            if ($archiveTrackId === false) {
              throw new Exception("Failed to create archive track");
            }

            // Move all positions from current track to archive track
            $query = "UPDATE " . $db->table('positions') . " SET track_id = ? WHERE track_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([ $archiveTrackId, $trackId ]);

            $db->commit();
          } catch (Exception $e) {
            if ($db->inTransaction()) {
              $db->rollBack();
            }
            syslog(LOG_ERR, "Auto-split failed: " . $e->getMessage());
          }
        }
      }
    }

    $image = null;
    if (!empty($imageMeta)) {
      $image = uUpload::add($imageMeta, $trackId);
    }

    $positionId = uPosition::add($auth->user->id, $trackId,
      $timestamp, $lat, $lon, $altitude, $speed, $bearing, $accuracy, $provider, $comment, $image);

    if ($positionId === false) {
      exitWithError("Server error");
    }
    exitWithSuccess();
    break;

  default:
    exitWithError("Unknown command");
    break;
}

?>