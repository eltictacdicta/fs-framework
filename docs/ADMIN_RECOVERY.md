# Recuperación de acceso administrador

Cómo recuperar el acceso cuando no se puede iniciar sesión como administrador:
contraseña olvidada, cuenta deshabilitada o un único admin inutilizable.

No hace falta subir ningún archivo PHP al servidor. El hash de la contraseña es
**portable**: se genera en cualquier máquina con PHP 8.2+ y se aplica en la base
de datos.

## Procedimiento

1. Ejecutá el script desde el repositorio:

   ```bash
   php scripts/reset-admin-password.php admin
   # Contraseña: ********
   ```

   Pide la contraseña por teclado sin mostrarla. Para automatización:

   ```bash
   echo 'MiClaveNueva2026' | php scripts/reset-admin-password.php admin
   ```

2. Copiá la sentencia que imprime:

   ```text
   Hash Argon2id (97 caracteres):
   $argon2id$v=19$m=65536,t=4,p=1$...

   Sentencia para phpMyAdmin / psql:
   UPDATE fs_users SET password = '$argon2id$...' WHERE nick = 'admin';
   ```

3. Ejecutala en phpMyAdmin, Adminer, `psql` o `mysql`.

4. Iniciá sesión con la contraseña nueva y cambiala desde la aplicación.

## Casos relacionados

La cuenta puede estar bloqueada por algo más que la contraseña:

```sql
-- Cuenta deshabilitada
UPDATE fs_users SET enabled = TRUE WHERE nick = 'admin';

-- Cuenta sin permisos de administrador
UPDATE fs_users SET admin = TRUE WHERE nick = 'admin';
```

Si el problema es que el rol no incluye las páginas necesarias, revisá
`fs_roles_user` y la asignación de páginas en `fs_roles_access`.

## Por qué no hay un script web de emergencia

El antiguo `emergency_admin_reset.php` tenía que subirse a la raíz web: un PHP
ejecutable en el docroot, cuya seguridad dependía de que alguien se acordara de
borrarlo. Y no aportaba nada, porque el hash no depende del servidor: alcanza con
ejecutar este script en tu máquina y pegar el resultado.

Un archivo PHP dejado en el docroot es superficie de ataque permanente; generar
el hash en otro lado no deja rastro en el servidor.

## Requisitos y límites

- **Largo de contraseña**: 8 a 32 caracteres (es lo que acepta
  `fs_user::set_password()`). El instalador exige 12 como mínimo para cuentas
  nuevas, así que 12+ es lo recomendable.
- **Algoritmo**: Argon2id; bcrypt si la instalación de PHP no lo soporta.
- **Columna**: `fs_users.password` es `varchar(100)` y un hash Argon2id con los
  parámetros actuales mide 97 caracteres. Si alguna vez se suben `memory_cost` o
  `time_cost`, hay que ampliar la columna primero o el hash no entra.
- **PostgreSQL y MySQL**: la misma sentencia sirve en ambos.

## Notas de seguridad

- El script lee la contraseña por STDIN y la oculta cuando hay terminal; no la
  recibe por `argv`, así que no queda en el historial del shell ni en la lista de
  procesos (`ps`).
- Nunca imprime la contraseña, sólo el hash.
- El script es exclusivamente CLI: si se sirve por web responde sin ejecutarse.
  Además `scripts/` está denegado en `.htaccess` y en `nginx-sample.conf`.
