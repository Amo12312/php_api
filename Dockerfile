FROM php:8.1-apache

# Install required extensions
RUN apt-get update && apt-get install -y \
    libssl-dev \
    && docker-php-ext-install mysqli pdo pdo_mysql

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . .

# Expose port 80
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
