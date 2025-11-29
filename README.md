Proyecto: Aportes - Hostinger-ready
Instrucciones:
1. Importa db.sql en phpMyAdmin.
2. Edita config.php con tus credenciales de MySQL y ADMIN_USER/ADMIN_PASS o exporta variables de entorno.
3. Sube toda la carpeta 'aportes_hostinger' al public_html de tu hosting.
4. Abre index.php en el navegador.
5. Inicia sesión en /login.php con las credenciales por defecto (admin / admin123) y cámbialas en config.php.
6. Para exportar CSV de un mes, selecciona mes/año y presiona "Exportar CSV".
7. Para imprimir en PDF, abre la vista mensual y usa "Imprimir" del navegador.

Notas de seguridad:
- Cambia ADMIN_USER y ADMIN_PASS en config.php inmediatamente o usa variables de entorno en Hostinger.
- Este proyecto es un punto de partida; para producción considera HTTPS y validaciones adicionales.

Funcionalidades añadidas:
- Login de administrador (login.php / logout.php)
- Solo administradores pueden editar aportes existentes (inputs aparecen solo para admin)
- Exportar CSV por mes (api/export_csv.php)
- Edición con inputs numéricos y validación (no permitir negativos)
- Estilos actualizados (colores y tipografía)
