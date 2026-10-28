const gateForm = document.getElementById('gate-trigger-form');
const btnScan = document.getElementById('btn-scan-entry');
const barrierSim = document.getElementById('barrier-simulation');

const edadInput = document.getElementById('edad');
const documentoContainer = document.getElementById('documento-container');
const labelDocumento = document.getElementById('label-documento');
const documentoInput = document.getElementById('documento');

if (gateForm) {
    gateForm.addEventListener('submit', function(e) {
        e.preventDefault();
        btnScan.classList.add('hidden');
        barrierSim.classList.remove('hidden');
        
        setTimeout(() => {
            gateForm.submit();
        }, 1800);
    });
}

if (edadInput) {
    edadInput.addEventListener('input', () => {
        const edad = parseInt(edadInput.value);

        if (isNaN(edad) || edad <= 0) {
            documentoContainer.classList.add('hidden');
            documentoInput.removeAttribute('required');
            return;
        }

        documentoContainer.classList.remove('hidden');
        documentoInput.setAttribute('required', 'true');

        if (edad >= 18) {
            labelDocumento.textContent = 'Cédula de Ciudadanía (CC):';
            documentoInput.placeholder = 'Ingrese su número de cédula';
        } else {
            labelDocumento.textContent = 'Tarjeta de Identidad (TI):';
            documentoInput.placeholder = 'Ingrese su tarjeta de identidad';
        }
    });
}