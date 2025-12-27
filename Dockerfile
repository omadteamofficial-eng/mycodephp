# 1. Asosiy image: PHP 8.2 va Apache
FROM php:8.2-apache

# 2. Kerakli tizim kutubxonalarini o'rnatish (zip va sqlite uchun)
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# 3. PHP kengaytmalarini o'rnatish (Database uchun)
RUN docker-php-ext-install pdo pdo_sqlite

# 4. Apache Rewrite modulini yoqish (agar kerak bo'lsa)
RUN a2enmod rewrite

# 5. Composerni o'rnatish
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 6. Ishchi papkani belgilash
WORKDIR /var/www/html

# 7. Barcha fayllarni konteynerga nusxalash
COPY . .

# 8. MUHIM: Agar asosiy faylingiz "bot.php" bo'lsa, uni "index.php" ga aylantiramiz.
# Chunki Apache avtomatik ravishda index.php ni qidiradi.
RUN if [ -f bot.php ]; then mv bot.php index.php; fi

# 9. Composer orqali kutubxonalarni o'rnatish
RUN composer install --no-dev --optimize-autoloader

# 10. SQLite bazasiga yozish huquqini berish (www-data useriga)
# Baza fayli va papkaga yozish huquqi berilmasa, "ReadOnly database" xatosi chiqadi
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html

# 11. Portni ochish (Render va boshqalar uchun standart 80)
EXPOSE 80
