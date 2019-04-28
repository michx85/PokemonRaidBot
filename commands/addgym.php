<?php
// Write to log.
debug_log('ADDGYM()');

// For debug.
// debug_log($update);
// debug_log($data);

// Check access.
bot_access_check($update, 'gym-add');

// Get gym name.
$input = trim(substr($update['message']['text'], 7));

// Count commas given in input.
$count = substr_count($input, ",");

// 1 comma as it should be?
// E.g. 52.5145434,13.3501189
if($count == 1) {
    $lat_lon = explode(',', $input);
    $lat = $lat_lon[0];
    $lon = $lat_lon[1];

// Lat and lon with comma instead of dot?
// E.g. 52,5145434,13,3501189
} else if($count == 3) {
    $lat_lon = explode(',', $input);
    $lat = $lat_lon[0] . '.' . $lat_lon[1];
    $lon = $lat_lon[2] . '.' . $lat_lon[3];
} else {
    // Invalid input - send the message and exit.
    $msg = getTranslation('invalid_input');
    sendMessage($update['message']['chat']['id'], $msg);
    exit();
}

// Set gym name.
$gym_name = '#' . $update['message']['from']['id'];

// Get address.
$addr = get_address($lat, $lon);
$address = format_address($addr);

// Insert / update gym.
try {

    global $db;

    // Build query to check if gym is already in database or not
    $rs = my_query("
    SELECT    COUNT(*)
    FROM      gyms
      WHERE   gym_name = '{$gym_name}'
     ");

    $row = $rs->fetch_row();

    // Gym already in database or new
    if (empty($row['0'])) {
        // insert gym in table.
        debug_log('Gym not found in database gym list! Inserting gym "' . $gym_name . '" now.');
        $query = '
        INSERT INTO gyms (gym_name, lat, lon, address, show_gym)
        VALUES (:gym_name, :lat, :lon, :address, 0)
        ';
        $msg = getTranslation('gym_added');
    } else {
        // Get gym by temporary name.
        $gym = get_gym_by_telegram_id($gym_name);

        // If gym is already in the database, make sure no raid is active before continuing!
        if($gym) {
            debug_log('Gym found in the database! Checking for active raid now!');
            $gym_id = $gym['id'];

            // Check for duplicate raid
            $duplicate_id = 0;
            $duplicate_id = active_raid_duplication_check($gym_id);

            // Continue with raid creation
            if($duplicate_id > 0) {
                debug_log('Active raid is in progress!');
                debug_log('Tell user to update the gymname and exit!');

                // Show message that a raid is active on that gym.
                $raid_id = $duplicate_id;
                $raid = get_raid($raid_id);

                // Build message.
                $msg = EMOJI_WARN . SP . getTranslation('raid_already_exists') . SP . EMOJI_WARN . CR . show_raid_poll_small($raid);

                // Tell user to update the gymname first to create another raid by location
                $msg .= getTranslation('gymname_then_location');
                $keys = [];

                // Send message.
                send_message($update['message']['chat']['id'], $msg, $keys, ['reply_markup' => ['selective' => true, 'one_time_keyboard' => true]]);

                exit();
            } else {
                debug_log('No active raid found! Continuing now ...');
            }
        } else {
            // Set gym_id to 0
            $gym_id = 0;
            debug_log('No gym found in the database! Continuing now ...');
        }

        // Update gyms table to reflect gym changes.
        debug_log('Gym found in database gym list! Updating gym "' . $gym_name . '" now.');
        $query = '
            UPDATE        gyms
            SET           lat = :lat,
                          lon = :lon,
                          address = :address
            WHERE      gym_name = :gym_name
        ';
        $msg = getTranslation('gym_updated');
    }

    $statement = $dbh->prepare($query);
    $statement->bindValue(':gym_name', $gym_name, PDO::PARAM_STR);
    $statement->bindValue(':lat', $lat, PDO::PARAM_STR);
    $statement->bindValue(':lon', $lon, PDO::PARAM_STR);
    $statement->bindValue(':address', $address, PDO::PARAM_STR);
    $statement->execute();

    // Get last insert id.
    if (empty($row['0'])) {
        $gym_id = $dbh->lastInsertId();
    }

    // Gym details.
    if($gym_id > 0) {
        $gym = get_gym($gym_id);
        $msg .= CR . CR . get_gym_details($gym);
    }
} catch (PDOException $exception) {

    error_log($exception->getMessage());
    $dbh = null;
    exit();
}

// Send the message.
sendMessage($update['message']['chat']['id'], $msg);

?>
