# Build de estilos

Los estilos (Tailwind + componentes de `assets/app.css`) se compilan a `assets/styles.css` (se sube al servidor ya compilado; el hosting no necesita Node).

Después de agregar clases nuevas en las vistas:

```bash
npx tailwindcss@3 -c build/tailwind.config.js -i build/tailwind.input.css -o assets/styles.css --minify
```
