# TmpText - Almacenamiento Temporal de Archivos y Texto

Un sistema simple y liviano para compartir temporalmente textos (logs/código) y archivos, desarrollado en PHP sin necesidad de base de datos.

## 🚀 Funcionalidades

- **Subida de texto o archivo**: Vía web o CLI (curl/bash).
- **URLs Únicas**: Generación de URL basada en UUID.
- **Vista Previa Inteligente**:
  - Syntax highlight para código y logs.
  - Vista previa para imágenes.
  - Descarga directa para otros tipos de archivo.
- **Expiración Automática**: Los archivos se eliminan después de 7 días.
- **Seguridad**:
  - Límite de velocidad por IP (10 subidas por minuto).
  - Protección contra XSS y CSRF.
  - Límite de tamaño de archivo (5MB).
  - Almacenamiento fuera de la raíz pública.
- **Sin Base de Datos**: Utiliza solo el sistema de archivos local.

## 🛠️ Requisitos

- PHP 7.4 o superior.
- Servidor Web (Apache, Nginx o el servidor embebido de PHP).

## 📥 Instalación

1. Clonar el repositorio:
   ```bash
   git clone https://github.com/paulocesargarcia/tmptextcom
   cd tmptextcom
   ```

2. Asegurarse de que el directorio `storage` y sus subdirectorios tengan permisos de escritura para el usuario del servidor web.

## 💻 Cómo usar

### Servidor de Desarrollo (PHP)
Para ejecutar rápidamente:
```bash
php -S localhost:8000 -t public public/index.php
```

### Vía Web
Acceder a `http://localhost:8000` en tu navegador para usar el formulario de subida.

### Vía CLI (Ejecución Remota)

Podés ejecutar el CLI directamente sin descargar nada, útil para servidores y automatizaciones rápidas:

```bash
# Enviar texto
bash <(curl -s "http://localhost:8000/cli.sh") -t "Hola Mundo"

# Enviar un archivo
bash <(curl -s "http://localhost:8000/cli.sh") -f mi-archivo.txt

# Enviar vía Pipe (stdin)
cat app.log | bash <(curl -s "http://localhost:8000/cli.sh")
```

### Vía CLI (Local)

Si preferís descargar el script:

```bash
curl -O http://localhost:8000/cli.sh
chmod +x cli.sh

./cli.sh -t "Texto local"
```

### Vía curl (Directo)

Si preferís no usar el script:

**Texto:** `curl -X POST --data "texto de prueba" http://localhost:8000/api/upload`

**Archivo:** `curl -F "file=@archivo.txt" http://localhost:8000/api/upload`

## 📂 Estructura del Proyecto

```
/public    - Raíz del servidor web (index.php)
/src       - Lógica del sistema (Clases PHP y plantillas)
/storage   - Archivos almacenados, metadados y logs
```

## 🛡️ Seguridad y Autor

- El sistema limita a **10 subidas por minuto por IP**.
- Autor: **Paulo Cesar Garcia**
- Repositorio: [https://github.com/paulocesargarcia/tmptextcom](https://github.com/paulocesargarcia/tmptextcom)
