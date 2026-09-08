# Empaquetado de distribución

PT-7.3 (sprint 6.5.1). Antes de este sprint no había una lista de
exclusión determinista para el ZIP de distribución — el criterio
dependía de qué se copiara a mano en cada release.

## Cómo generar el ZIP

```bash
./scripts/build-dist.sh          # usa la versión de atora_lms.php
./scripts/build-dist.sh 6.13.3   # o una versión explícita
```

Resultado: `dist/atora-lms-<version>.zip`, sin `.git/`, `.claude/`,
`.qodo/`, `agents/`, `tests/`, `docs/` ni archivos de desarrollo
(`composer.json`, `phpunit.xml`, `vendor/`, `CHANGELOG.md` interno,
`SECURITY-AUDIT.md`). La lista completa de exclusiones vive en
`.distignore`, en la raíz del repo — es la fuente de verdad, no este
documento.

## Antes de empaquetar

1. Los tres puntos de versión deben coincidir: cabecera `Version:` de
   `atora_lms.php`, `ATORA_LMS_VERSION`, y `Stable tag` de
   `readme.txt`.
2. Working tree limpio (`git status` sin cambios sin commitear).

## Si agregas un archivo o carpeta nueva al repo

Si es código o assets del plugin en tiempo de ejecución, no hace falta
tocar nada — `.distignore` excluye por nombre, no por lista blanca. Si
es material de desarrollo (otro script de build, otra carpeta de
herramientas), añádelo a `.distignore` explícitamente.


## Dependencias de desarrollo

`composer.lock` se versiona en el repositorio para que PHPUnit, Brain Monkey y Mockery se instalen de forma reproducible. El archivo se excluye del ZIP final mediante `.distignore`, porque no es una dependencia de ejecución del plugin.
