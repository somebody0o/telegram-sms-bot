FROM php:8.1-alpine

# تثبيت التبعيات
RUN apk add --no-cache curl

# إنشاء مجلد التطبيق
WORKDIR /app

# نسخ الملفات
COPY . .

# إنشاء مجلدات البيانات
RUN mkdir -p /app/data/users /app/data/balance \
    && chmod -R 777 /app/data

# تشغيل التطبيق
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "index.php"]
