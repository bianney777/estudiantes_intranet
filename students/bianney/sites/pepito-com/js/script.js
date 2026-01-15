// scripts
// Pequeña sorpresa cuando se hace clic en el nombre
const nombre = document.querySelector('h1');

nombre.addEventListener('click', () => {
    nombre.style.color = '#e63946';
    alert('¡Pepito dice: Gracias por visitar mi sitio! 🌟');
});

// Efecto de entrada suave para los botones
const botones = document.querySelectorAll('.link-btn');
botones.forEach((btn, index) => {
    btn.style.opacity = '0';
    btn.style.transform = 'translateY(20px)';
    
    setTimeout(() => {
        btn.style.transition = 'all 0.5s ease';
        btn.style.opacity = '1';
        btn.style.transform = 'translateY(0)';
    }, 200 * index);
});