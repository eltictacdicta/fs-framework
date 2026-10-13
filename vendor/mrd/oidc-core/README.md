# oidc-core

Librería compartida para extraer el núcleo OIDC reutilizable entre clientes de One Login.

## Objetivo

Esta librería debe contener solo lógica agnóstica del framework:

- intercambio de tokens
- validación de `id_token`
- resolución de endpoints OIDC
- utilidades de `state` y `nonce`
- DTOs y excepciones de protocolo

No debe contener:

- sesiones PHP o WordPress
- controladores HTTP
- hooks de WordPress
- respuestas Symfony
- mapeo de usuarios de cada aplicación

## Publicación privada en GitHub

Esta carpeta está pensada para convertirse en un repositorio Git independiente, por ejemplo `git@github.com:TU-USUARIO/oidc-core.git`.

En este workspace no conviene consumirla mediante Composer `path repositories` porque los proyectos se ejecutan con `ddev` y cada contenedor solo ve su propio directorio de proyecto.

Cuando el repositorio privado exista en GitHub, cada proyecto deberá usar Composer con una entrada `vcs` apuntando al repositorio privado y fijar una versión etiquetada.

Ejemplo de configuración en un proyecto consumidor:

```json
{
	"repositories": [
		{
			"type": "vcs",
			"url": "git@github.com:TU-USUARIO/oidc-core.git"
		}
	],
	"require": {
		"mrd/oidc-core": "^0.1"
	}
}
```

Flujo recomendado:

1. Inicializar este directorio como repositorio Git independiente.
2. Crear el repositorio privado en GitHub.
3. Añadir `origin` y subir `main`.
4. Crear el primer tag, por ejemplo `v0.1.0`.
5. Añadir la configuración `vcs` en cada proyecto consumidor.
6. Ejecutar `composer update mrd/oidc-core` dentro de cada proyecto.
7. Publicar y desplegar la misma etiqueta en todos los consumidores cuando el cambio afecte al contrato compartido.

Si se renombra el paquete Composer, el orden es obligatorio:

1. Publicar primero en el repositorio de la librería el `composer.json` con el nuevo `name`.
2. Crear y subir la etiqueta correspondiente.
3. Solo entonces actualizar los consumidores y regenerar sus `composer.lock`.

## Primera extracción disponible

La librería ya contiene una primera pieza reutilizable:

- `OneLogin\OidcCore\Jwt\IdTokenValidator` para validar `id_token` RS256 contra un JWKS.
- `OneLogin\OidcCore\Jwt\Jwt` para decodificar el payload sin verificar firma cuando solo hace falta leer claims.
- `OneLogin\OidcCore\Crypto\RsaJwkConverter` para convertir una clave RSA JWK a PEM.

`OneLogin\OidcCore\Jwt\Jwt` es una utilidad de decodificación, no de validación: no verifica firmas ni otras propiedades de seguridad del token, así que no debe usarse por sí sola para autenticación o autorización.

Ejemplo mínimo:

```php
use OneLogin\OidcCore\Jwt\IdTokenValidator;

$validator = new IdTokenValidator('https://issuer.example', 'client-id', 'OIDC CLIENT');
$claims = $validator->validate($idToken, $jwks);
```

## Segunda extracción disponible

La librería también expone las piezas compartidas para discovery local:

- `OneLogin\OidcCore\Discovery\OidcEndpointResolver` para derivar `issuer`, `token`, `userinfo` y `jwks.json`.
- `OneLogin\OidcCore\Jwks\JwksDocument` para validar y extraer la lista `keys` de un documento JWKS.

## Tercera extracción disponible

La librería también centraliza la capa de payload/respuesta del token endpoint:

- `OneLogin\OidcCore\Token\TokenRequestBuilder` para construir los payloads de `authorization_code` y `password`.
- `OneLogin\OidcCore\Token\TokenSet` para exponer los tokens del flujo OIDC con una API tipada.

## Pivot a Symfony

La librería ya puede delegar el transporte HTTP OIDC en Symfony 7.4:

- `symfony/http-client` pasa a ser dependencia del core.
- `OneLogin\OidcCore\Client\OidcProviderClient` centraliza `token`, `userinfo` y `jwks.json`.
- Los consumidores pueden quedarse solo con el mapeo de errores de UI y la adaptación a su framework.

## Configuración tipada

La configuración del cliente también se puede resolver con Symfony:

- `symfony/options-resolver` pasa a formar parte del core.
- `OneLogin\OidcCore\Config\OidcProviderConfiguration` valida y normaliza `issuer`, `client_id`, `scope`, `verify_peer` y `timeout`.
- `OidcProviderClient::fromConfiguration()` permite crear el cliente desde una configuración ya resuelta.

## DTOs y servicios de identidad

La capa de protocolo ya no necesita arrays sin contrato para el camino feliz:

- `OneLogin\OidcCore\Token\TokenSet` encapsula `access_token`, `refresh_token` e `id_token`.
- `OneLogin\OidcCore\Exception\*` centraliza fallos de transporte, autenticación e identidad.
- `OneLogin\OidcCore\Identity\AuthenticatedIdentity` garantiza un `sub` válido y expone la identidad autenticada.
- `OneLogin\OidcCore\Identity\OidcIdentityResolver` valida `id_token`, carga JWKS, fusiona `userinfo` y devuelve la identidad autenticada.

## Análisis de código muerto

La librería ya puede ejecutar una pasada específica de dead code sobre todo `src/` y `tests`:

```bash
cd oidc-core
composer phpstan:dead-code
```

Como `oidc-core` es una librería compartida y parte de su API pública se consume desde otros proyectos, las clases exportadas del core se marcan con `@api` para que `shipmonk/dead-code-detector` no las reporte como muertas solo porque su uso real ocurre fuera de este repositorio.

Regla práctica:

- si una clase o método forma parte del contrato público entre proyectos, mantenlo marcado con `@api`.
- si una pieza es solo interna del core, deja que el detector la evalúe normalmente.

Esto no afecta al flujo de sincronización por artifact: `scripts-onlogin/sync-oidc-core.sh` ya empaqueta el working tree actual y estos cambios viajarán con la siguiente sincronización/versión.