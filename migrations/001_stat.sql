-- One row per page view. No IP, no User-Agent, no session id, no cookie -
-- visitor_hash is a hash of (IP, User-Agent, a secret, today's date) that
-- changes every day and cannot be reversed; see HitRecorder's own doc
-- comment for why that is what makes this addon consent-free. No offer id:
-- an offer's own path (under /offers/) already names it, resolved to a
-- title only when shown (StatQuery), so a deleted offer's history is never
-- lost to a foreign key and no join is needed to record a hit.
CREATE TABLE x_stat_hit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    day DATE NOT NULL,
    path VARCHAR(255) NOT NULL,
    referrer_domain VARCHAR(255) NULL,
    device ENUM('desktop', 'mobile', 'tablet') NOT NULL DEFAULT 'desktop',
    country CHAR(2) NULL,
    visitor_hash CHAR(16) NOT NULL,
    KEY idx_day (day),
    KEY idx_path (day, path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
