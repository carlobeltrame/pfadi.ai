CREATE TABLE `samstag_stories` (
                                   `id` int(11) NOT NULL AUTO_INCREMENT,
                                   `title` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
                                   `target_group` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                                   `timeframe` varchar(2048) COLLATE utf8mb4_unicode_ci,
                                   `story` text COLLATE utf8mb4_unicode_ci NOT NULL,
                                   `cost` float NOT NULL,
                                   `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `samstag_programme` (
                                     `id` int(11) NOT NULL AUTO_INCREMENT,
                                     `title` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
                                     `target_group` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                                     `timeframe` varchar(2048) COLLATE utf8mb4_unicode_ci,
                                     `story` text COLLATE utf8mb4_unicode_ci NOT NULL,
                                     `programme` text COLLATE utf8mb4_unicode_ci NOT NULL,
                                     `cost` float NOT NULL,
                                     `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                     PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `samstag_material` (
                                    `id` int(11) NOT NULL AUTO_INCREMENT,
                                    `title` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
                                    `target_group` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                                    `timeframe` varchar(2048) COLLATE utf8mb4_unicode_ci,
                                    `story` text COLLATE utf8mb4_unicode_ci NOT NULL,
                                    `programme` text COLLATE utf8mb4_unicode_ci NOT NULL,
                                    `material` text COLLATE utf8mb4_unicode_ci NOT NULL,
                                    `cost` float NOT NULL,
                                    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
