FROM php:8.1-alpine

# تثبيت التبعيات المطلوبة
RUN apk add --no-cache \
    curl \
    git \
    zip \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip

# إنشاء مجلد التطبيق
WORKDIR /app

# نسخ ملفات المشروع
COPY . .

# إنشاء مجلدات البيانات
RUN mkdir -p /app/data/users /app/data/balance \
    && chmod -R 777 /app/data

# تعيين المستخدم المناسب
USER nobody

# تشغيل التطبيق
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "index.php"]
