# Operación del panel Ombara

## Primer acceso

1. Configure `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASS` en el entorno del servidor. El usuario de MySQL debe tener permisos para crear y modificar las tablas de la aplicación.
2. Configure `ADMIN_SETUP_TOKEN` con un valor aleatorio fuerte antes de abrir `backend/admin/login.php`.
3. En el primer acceso, cree la cuenta de administrador con ese token y una contraseña de al menos 12 caracteres. Retire `ADMIN_SETUP_TOKEN` del entorno después de crearla.
4. Inicie sesión como administrador y registre el inventario real de habitaciones desde Recepción. Las reservas web solo se confirman cuando una habitación de esa categoría está disponible para todas las fechas solicitadas.
5. Desde Administración > Personal, cree las cuentas de recepción y configure su correo y WhatsApp internacional en formato E.164, por ejemplo `+628123456789`.

## Notificaciones

- Correo: configure `MAIL_FROM` y un transporte de correo funcional para PHP (`mail()`), normalmente el MTA del servidor. El comprobante se envía al huésped y a las cuentas activas de administración y recepción.
- WhatsApp: configure `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_ACCESS_TOKEN` y, si corresponde, `WHATSAPP_GRAPH_VERSION`. El valor predeterminado del nombre de plantilla es `reservation_confirmation`; puede cambiarse con `WHATSAPP_TEMPLATE_NAME`.
- La plantilla de WhatsApp debe estar aprobada en Meta en español y contener siete variables de cuerpo, en este orden: código, huésped, tipo de habitación, número de habitación, llegada, salida y total.
- Los intentos quedan en `notification_logs` como `sent`, `failed` o `skipped`. `sent` significa aceptado por el transporte, no confirmación de entrega al destinatario.

Sin inventario registrado, transporte de correo o credenciales del proveedor WhatsApp, la reserva o el canal afectado no se confirmarán como entregados. Los secretos deben configurarse en el entorno del servidor, nunca en HTML ni en el repositorio.