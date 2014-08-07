<?php

    /*
     * Written By: AtheistP3ace
     * -> PiXELHD.me
     * Most of this code will work on gazelle based trackers.
     * Added some extra security in as well. Some of that extra security
     * will require code added to other parts of your site. All this code
     * does not use any of the wrappers from original gazelle code
     * except Cache which also can be stripped out if your site is small.
     * Mostly you only need your specific SQL added to match your tables.
     * 
     */

    // Make sure we can use the mysqli extension
    if (!extension_loaded ('mysqli')) {
        echo json_encode(array("error" => "Mysqli Extension not loaded."));
        die();
    }

    // Lets prevent people from clearing cache (Removable)
    if (isset($_REQUEST['clearcache'])) {
        unset($_REQUEST['clearcache']);
    }

    // Site configuration (Removable)
    require 'classes/config.php';

    // Lets cache to speed things up on subsequent calls (Removable)
    require SERVER_ROOT . '/classes/class_cache.php';
    $Cache = new CACHE;

    // Get the Username, passkey and\or IMDb ID\search string from request
    $Username = db_string($_REQUEST['user']);
    $PassKey = db_string($_REQUEST['passkey']);
    $IMDbID = db_string($_REQUEST['imdbid']);
    $SearchString = db_string($_REQUEST['search']);

    // Set content type
    header('Content-Type: application/json');

    // Do parameters match what we expect?
    if (empty($_REQUEST['passkey']) || strlen($_REQUEST['passkey']) != 32 || (empty($_REQUEST['imdbid']) && empty($_REQUEST['search'])) || empty($_REQUEST['user'])) {
        echo json_encode(array("error" => "Incorrect parameters."));
        die();
    }

    // Connect to DB manually for exposed service. Variables below come from config.php loaded above
    $DbLink = mysqli_connect(SQLHOST, SQLLOGIN, SQLPASS, SQLDB, SQLPORT, SQLSOCK) or die("Error: " . mysqli_error($DbLink));

    // Get needed data on user attached to Username and passkey
    // Forcing both values to be passed makes security a bit harder and allows us to make a couch potato key
    $FindUserQuery = $DbLink->query("SELECT
                                    UserID,
                                    CouchPotatoKey,
                                    AuthorizationKey
                                    FROM some_user_table
                                    WHERE PassKey = '$PassKey'
                                    AND Username = '$Username'");

    // Does user exist?
    if ($FindUserQuery->num_rows == 0) {
        echo json_encode(array("error" => "Death by authorization."));
        die();
    }
    $FindUserResult = mysqli_fetch_array($FindUserQuery);
    $UserID = $FindUserResult['UserID'];
    $CouchPotatoKey = $FindUserResult['CouchPotatoKey'];
    $AuthKey = $FindUserResult['AuthorizationKey'];

    /*
     * The next bit is all for security. You can remove this if you don't care about that.
     * It basically checks if user is an enabled user, has permissions to access service,
     * user enabled access from their profile to generate a hashed passkey. None is
     * necessary but helpful if security is your thing.
     */

    // Load if user is enabled and cache for later
    if (!$Enabled = $Cache->get_value ('enabled_' . $UserID)) {
        $UserEnabled = $DbLink->query("SELECT Enabled FROM some_user_table WHERE UserID = '$UserID'");
        $Enabled = mysqli_fetch_array($UserEnabled);
        $Enabled = $Enabled['Enabled'];
        $Cache->cache_value ('enabled_' . $UserID, $Enabled, 259200);
    }

    // Load if user has permissions to access Couch Potato and cache for later (Removable)
    if (!$CPPermission = $Cache->get_value ('this_user_can_pull_cp_feed_' . $UserID)) {
        $UserAllowed = $DbLink->query("SELECT UserPermission FROM some_user_table WHERE UserID = '$UserID'");
        $PermissionID = mysqli_fetch_array($UserAllowed);
        $PermissionID = $PermissionID['UserPermission'];
        if (!$Permission = $Cache->get_value ('permission_' . $PermissionID)) {
            $CheckPerms = $DbLink->query("SELECT SpecificPermissions FROM some_permissions_table WHERE PermissionID = '$PermissionID'");
            $Permission = mysqli_fetch_array($CheckPerms);
            $Permission['SpecificPermissions'] = unserialize ($Permission['Permissions']);

            // If class level permissions is empty check custom permissions (Removable)
            if (empty($Permission['Permissions']['this_user_can_pull_cp_feed_'])) {
                $UserCustom = $DbLink->query("SELECT CustomPermissions FROM some_user_table WHERE ID = '$UserID'");
                $CustomPermission = mysqli_fetch_array($UserCustom);
                $CustomPermission['CustomPermissions'] = unserialize ($CustomPermission['CustomPermissions']);
                $Permission['Permissions'] = array_merge ($Permission['Permissions'], $CustomPermission['CustomPermissions']);
            }
            $Cache->cache_value ('permission_' . $PermissionID, $Permission, 259200);
        }
        $CPPermission = $Permission['Permissions']['users_can_pull_cp_feed'];
        $Cache->cache_value ('this_user_can_pull_cp_feed_' . $UserID, $CPPermission, 259200);
    }

    // Load if user has enabled Couch Potato Access from their profile and cache for later (Removable)
    // Forcing user to enable on profile is where generation of couch potato key is
    if (!$CPEnabled = $Cache->get_value ('user_enabled_cp_on_profile' . $UserID)) {
        $UserCPEnabled = $DbLink->query("SELECT CouchPotatoOn FROM some_user_table WHERE UserID = '$UserID'");
        $CPEnabled = mysqli_fetch_array($UserCPEnabled);
        $CPEnabled = $CPEnabled['CouchPotatoOn'];
        $Cache->cache_value ('user_enabled_cp_on_profile' . $UserID, $CPEnabled, 259200);
    }

    // Check all above conditions plus some hash for good measure (Removable)
    if (md5($UserID . RSS_HASH . $PassKey) != $CouchPotatoKey || $Enabled != 1 || $CPEnabled != 1 || $CPPermission != 1) {
        echo json_encode(array("error" => "Death by authorization."));
        die();
    }
    else {
        // Things look good, let's find this media
        $MediaQuery = "SELECT
                       ID,
                       Name,
                       FileList,
                       Media,
                       Audio,
                       Resolution,
                       ReleaseType,
                       Edition,
                       Size,
                       Leechers,
                       Seeders,
                       Genres
                       FROM some_torrents_table
                       WHERE";
        if (!empty($IMDbID)) {
            $MediaQuery .= " IMDb_ID = '$IMDbID'";
        }
        if (!empty($SearchString)) {
            if (!empty($IMDbID)) {
                $MediaQuery .= " AND";
            }
            $MediaQuery .= " Name LIKE '%$SearchString%'";
        }
        $MediaLookupResults = $DbLink->query($MediaQuery);

        // Check for results
        $TotalResults = $MediaLookupResults->num_rows;
        if ($TotalResults == 0) {
            echo json_encode(array("total_results" => $TotalResults));
        }
        else {
            // Initialize for output
            $JSONOutput = array();

            // For each returned row build JSON output
            while($ResultRow = mysqli_fetch_array($MediaLookupResults)) {

                // Release Name
                $Name = $ResultRow['Name'];

                // Release Year
                $Year = $ResultRow['Year'];

                // Torrent ID
                $TorrentID = $ResultRow['ID'];

                // Get files included (Original code had file sizes after each file. Remove them)
                // If multiple files we separate by triple pipe so explode on that
                $FilesArray = array();
                $Files = preg_replace('/{\{\{([^\{]*)\}\}\}/i', '', $ResultRow['FileList']);
                $FilesArray = explode("|||", $Files);

                // Build details page URL
                $DetailsURL = "https://some.torrent.site/torrents.php?id=".$ResultRow['GroupID'];

                // Build download URL
                $DownloadURL = "https://some.torrent.site/torrents.php?action=download&authkey=" . $AuthKey . "&torrent_pass=" . $PassKey . "&id=" . $TorrentID;

                // Add in IMDb ID for searches by text
                $IMDB_ID = $ResultRow['IMDb_ID'];

                // Resolution
                $Resolution = $ResultRow['Resolution'];

                // Media
                $Media = $ResultRow['Media'];

                // Audio
                $Audio = $ResultRow['Audio'];

                // Release Type
                $ReleaseType = $ResultRow['ReleaseType'];

                // Edition (e.g. Theatrical. Director's Cut, Extended)
                $Edition = $ResultRow['Edition'];

                // Get download size (B -> KB -> MB)
                $MediaSize = round(intval($ResultRow['Size']) / 1024 / 1024, 0);

                // Get Leechers
                $Leechers = intval($ResultRow['Leechers']);

                // Get Seeders
                $Seeders = intval($ResultRow['Seeders']);

                // Turn tags into array
                $Tags = explode(" ", $ResultRow['TagList']);

                // movie_name + edition + movie_year + resolution + audio + media + release_type
                $ReleaseName = str_replace(" ", ".", $Name).".".str_replace(" ", ".", $Edition).".".$Year.".".$Resolution.".".$Audio.".".$Media."-".str_replace(" ", "", $ReleaseType);

                // Build array for JSON encoding
                $Details = array("movie_name" => $Name,
                                 "movie_year" => $Year,
                                 "release_name" => $ReleaseName,
                                 "torrent_id" => $ID,
                                 "files" => $FilesArray,
                                 "details_url" => $DetailsURL,
                                 "download_url" => $DownloadURL,
                                 "imdb_id" => $IMDB_ID,
                                 "resolution" => $Resolution,
                                 "media" => $Media,
                                 "audio" => $Audio,
                                 "release_type" => $ReleaseType,
                                 "edition" => $Edition,
                                 "size" => $MediaSize,
                                 "leechers" => $Leechers,
                                 "seeders" => $Seeders,
                                 "tags" => $Tags);

                // Add to final output array
                array_push($JSONOutput, $Details);
            }

            // Encode and return data!!
            echo json_encode(array("results" => $JSONOutput, "total_results" => $TotalResults));
        }
    }
