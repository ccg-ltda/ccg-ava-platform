/**
 * What each appearance setting of a channel does, in the words shown to the client (tooltips of Chatbots > Canales y
 * apariencia). The allowed values and limits come from the server (`options` in config/chatbots.php); only the
 * explanations live here. Sliders give a function of the current value, discrete choices a text per option.
 */

/** Names of the shadow levels (0 = none). */
export const shadowNames = ['Sin sombra', 'Muy suave', 'Suave', 'Marcada', 'Intensa'];

export const describeButtonSize = (px) => {
    const feel = px <= 52 ? 'Discreto: ocupa poco espacio sobre tu página.' : px <= 64 ? 'Equilibrado: se ve bien sin tapar el contenido.' : 'Muy visible: ocupa más espacio sobre tu página.';

    return `El botón mide ${px} × ${px} px (un píxel es un punto de la pantalla). ${feel}`;
};

export const describeChatSize = (width) => `La ventana del chat mide ${width} px de ancho y ${2 * width - 200} px de alto. En pantallas pequeñas se ajusta sola al espacio disponible.`;

export const describeShape = (percent) => {
    if (percent >= 50) return 'Botón totalmente redondo, como un círculo.';
    if (percent === 0) return 'Esquinas rectas: el botón es un cuadrado.';

    return `Esquinas redondeadas (${percent} % del lado del botón). Cuanto más alto, más se acerca a un círculo.`;
};

export const describeShadow = (level) =>
    [
        'Sin sombra: el botón se ve plano sobre la página.',
        'Sombra muy ligera: apenas separa el botón del fondo.',
        'Sombra suave: separa el botón del fondo sin llamar la atención.',
        'Sombra marcada: el botón destaca y parece flotar sobre la página.',
        'Sombra intensa: máximo relieve, el botón resalta mucho.',
    ][level];

export const describeChatRadius = (px) => (px === 0 ? 'Esquinas rectas en la ventana del chat.' : `Las esquinas de la ventana del chat se redondean ${px} px. Más píxeles, esquinas más suaves.`);

/** Explanations of the discrete choices, by setting and value. */
export const optionHelp = {
    position: {
        'bottom-right': 'El botón aparece en la esquina inferior derecha de tu página.',
        'bottom-left': 'El botón aparece en la esquina inferior izquierda de tu página.',
    },
    open_behavior: {
        click: 'El chat se abre solo cuando el visitante pulsa el botón.',
        auto: 'El chat se abre solo a los 5 segundos de entrar a la página (en celulares no se abre solo).',
    },
    icon: {
        avatar: 'El botón muestra la imagen del chatbot. Si no tiene avatar, usa el icono del canal.',
        channel: 'El botón muestra el icono del canal (chat o WhatsApp).',
    },
};

/** Adds the explanation of each option of `setting` to a list of `{ value, label }`. */
export const withHelp = (setting, options) => options.map((option) => ({ ...option, description: optionHelp[setting]?.[option.value] }));
