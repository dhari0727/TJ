<?php
// Merged into trip.php (one page for itinerary + route). Old links keep working.
header('Location: trip.php?' . http_build_query(array_merge($_GET, ['tab' => 'itinerary'])));
exit;
