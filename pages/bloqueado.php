<?php $emp = empresa(); $mem = membresia_estado(); ?><!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Acceso suspendido</title><link rel="stylesheet" href="assets/styles.css"></head>
<body class="min-h-screen bg-slate-100 grid place-items-center p-6 font-sans">
<div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-8 max-w-md text-center">
  <div class="w-14 h-14 mx-auto rounded-2xl bg-rose-100 text-rose-600 grid place-items-center text-2xl font-bold">!</div>
  <h1 class="text-xl font-bold mt-4"><?= e($emp['nombre'] ?? '') ?></h1>
  <p class="text-slate-600 mt-2"><?= e($mem['msg']) ?>.</p>
  <p class="text-slate-500 text-sm mt-1">Comunícate con tu proveedor del sistema para renovar el servicio.</p>
  <a href="index.php?p=logout" class="inline-block mt-6 px-5 py-2.5 rounded-xl bg-slate-900 text-white font-semibold">Salir</a>
</div></body></html>
