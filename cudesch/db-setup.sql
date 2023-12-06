CREATE TABLE `cudesch_literature` (
                                   `id` int(11) NOT NULL AUTO_INCREMENT,
                                   `title` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
                                   `documents` JSON,
                                   `literature` text COLLATE utf8mb4_unicode_ci NOT NULL,
                                   `cost` float NOT NULL,
                                   `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
