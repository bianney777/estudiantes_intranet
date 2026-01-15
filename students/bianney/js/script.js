// Función para mostrar el formulario al hacer clic en el botón
function mostrarContacto() {
    const form = document.getElementById('contact-form');
    form.classList.toggle('hidden');
    
    // Scroll suave hacia el formulario
    form.scrollIntoView({ behavior: 'smooth' });
}

// Manejo del envío del formulario
document.getElementById('car-form').addEventListener('submit', function(e) {
    e.preventDefault();
    alert('¡Gracias! El vendedor se pondrá en contacto contigo pronto.');
    this.reset(); // Limpia el formulario
});