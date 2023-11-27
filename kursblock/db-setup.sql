CREATE TABLE `kursblock_goals` (
                                   `id` int(11) NOT NULL AUTO_INCREMENT,
                                   `title` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
                                   `age_group` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                                   `target_group` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                                   `motto` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
                                   `contents` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
                                   `goals` text COLLATE utf8mb4_unicode_ci NOT NULL,
                                   `cost` float NOT NULL,
                                   `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `kursblock_programme` (
                                     `id` int(11) NOT NULL AUTO_INCREMENT,
                                     `title` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
                                     `age_group` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                                     `target_group` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                                     `motto` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
                                     `contents` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
                                     `goals` text COLLATE utf8mb4_unicode_ci NOT NULL,
                                     `programme` text COLLATE utf8mb4_unicode_ci NOT NULL,
                                     `cost` float NOT NULL,
                                     `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
