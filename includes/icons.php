<?php
/**
 * ไอคอนจาก Feather Icons (MIT, Cole Bemis — ดูเครดิตใน CREDITS.md): ใช้ไฟล์ sprite assets/vendor/feather-sprite.svg
 * ใช้: icon('home') · icon('trash-2', 'text-red-700') — ชื่อไอคอนดูที่ https://feathericons.com · สไตล์พื้นฐานอยู่ที่คลาส .icon (assets/css/input.css)
 * ไอคอนเป็นของประดับ (aria-hidden) ข้อความข้าง ๆ ต้องสื่อความหมายครบเอง · ถ้าใช้ปุ่มที่มีแต่ไอคอน ให้ใส่ aria-label ที่ปุ่ม
 */
function icon(string $name, string $class = ''): string
{
    return '<svg class="icon' . ($class !== '' ? ' ' . e($class) : '') . '" aria-hidden="true" focusable="false"><use href="' . e(BASE) . 'assets/vendor/feather-sprite.svg#' . e($name) . '"></use></svg>';
}
