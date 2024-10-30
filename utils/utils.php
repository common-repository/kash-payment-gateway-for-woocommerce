<?php

function get_timestamp() {
    $date_utc = new DateTime(null, new DateTimeZone("UTC"));
    return substr_replace($date_utc->format(DATE_ATOM), "Z", -6);
}
