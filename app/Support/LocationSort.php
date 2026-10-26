<?php

declare(strict_types=1);

namespace App\Support;

final class LocationSort
{
    // LIKE has no word boundary, so the bare "US" is matched only as a whole word and "usa" is safe ("Russia", "Austria" contain neither).
    private const string US = "location LIKE '%united states%' OR location LIKE '%usa%' OR location LIKE '%north america%' OR location LIKE '%americas%' OR location = 'US' OR location LIKE 'US %' OR location LIKE '% US' OR location LIKE '% US %' OR location LIKE '%, US%'";

    /** SQL ORDER BY fragment: US locations first, then Worldwide, then everything else A–Z, no location last. */
    public static function usFirst(): string
    {
        return 'CASE WHEN location IS NULL THEN 3 WHEN '.self::US." THEN 0 WHEN location LIKE '%worldwide%' THEN 1 ELSE 2 END, location";
    }
}
