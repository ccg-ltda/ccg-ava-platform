import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useAccess } from '@/Components/Access/AccessContext';
import { AccessInput, AccessPasswordInput } from '@/Components/Access/AccessInput';
import SignupLayout from '@/Layouts/SignupLayout';
import { formatMoney } from '@/lib/format';

const PERIOD = { monthly: 'al mes', yearly: 'al año' };
const SOLUTION_ICONS = {
    custom: 'M12 3l2.5 5 5.5.8-4 3.9.9 5.5L12 15.6 7.1 18.2l.9-5.5-4-3.9 5.5-.8L12 3z',
    default: 'M3 7h18M3 12h18M3 17h18',
};
/** A limit as it is really configured: no value = the plan sets none (never presented as zero or invented). */
const limitText = (limit) => (limit.value === null || limit.value === undefined ? 'Sin límite definido' : `${Number(limit.value).toLocaleString('es-CO')} ${limit.unit}`);
const isEmail = (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);

/** Progress indicator: the steps the server computed (done / current / todo). */
function Steps({ steps }) {
    return (
        <ol className="signup-steps" aria-label="Progreso del registro">
            {steps.map((step) => (
                <li key={step.key} data-state={step.state} aria-current={step.state === 'current' ? 'step' : undefined}>
                    {step.label}
                </li>
            ))}
        </ol>
    );
}

function Actions({ left, children }) {
    return (
        <div className="signup-actions">
            <div className="group">{left}</div>
            <div className="group">{children}</div>
        </div>
    );
}

/** Posts a step without form data (back / cancel / restart). */
const go = (name, data = {}) => router.post(route(name), data, { preserveScroll: true });

function StepEmail({ email, mailUsable, codeMinutes, offersAvailable }) {
    const { showToast } = useAccess();
    const form = useForm({ email: email ?? '' });
    const [error, setError] = useState('');

    const submit = (e) => {
        e.preventDefault();

        if (!isEmail(form.data.email.trim())) {
            setError('Ingresa un correo electrónico válido');
            showToast('Por favor, corrige los errores del formulario', 'error');
            return;
        }

        form.post(route('signup.code.send'), { onError: () => showToast('No pudimos enviar el código', 'error') });
    };

    return (
        <form onSubmit={submit} noValidate>
            <h2 className="signup-title">Empecemos por tu correo</h2>
            <p className="signup-lead">Te enviaremos un código de verificación de un solo uso. Lo escribirás en el siguiente paso para confirmar que el correo es tuyo; caduca en {codeMinutes} minutos.</p>

            {!offersAvailable && (
                <div className="signup-note warning" role="status">
                    <div>
                        <strong>Actualmente no hay ofertas disponibles para contratar en línea.</strong> Todavía no se ha publicado ningún plan en esta plataforma, así que no podemos iniciar un registro. Si necesitas ayuda, contacta con el soporte de CCG.
                    </div>
                </div>
            )}

            {!mailUsable && (
                <div className="signup-note warning" role="alert">
                    El envío de correos no está configurado en este entorno, así que no podemos enviar el código. Contacta con soporte.
                </div>
            )}

            <AccessInput
                id="signup_email"
                label="Correo electrónico"
                type="email"
                value={form.data.email}
                onChange={(e) => {
                    form.setData('email', e.target.value);
                    form.clearErrors('email');
                    setError('');
                }}
                message={error || form.errors.email || ''}
                placeholder="tu@empresa.com"
                autoComplete="email"
                autoFocus
            />

            <Actions left={<button type="button" className="btn-ghost" onClick={() => router.visit(route('pre-login'))}>Volver al acceso</button>}>
                <button type="submit" className="login-btn" disabled={form.processing || !mailUsable || !offersAvailable}>
                    {form.processing ? 'Enviando…' : 'Enviar código'}
                </button>
            </Actions>
        </form>
    );
}

function StepCode({ email, resendSeconds, codeMinutes }) {
    const { showToast } = useAccess();
    const form = useForm({ code: '' });
    const resend = useForm({ email });
    const [wait, setWait] = useState(resendSeconds);

    useEffect(() => {
        if (wait <= 0) return undefined;
        const timer = setTimeout(() => setWait((value) => value - 1), 1000);

        return () => clearTimeout(timer);
    }, [wait]);

    const submit = (e) => {
        e.preventDefault();

        if (!/^\d{6}$/.test(form.data.code)) {
            form.setError('code', 'El código tiene 6 dígitos.');
            return;
        }

        form.post(route('signup.code.verify'), { onError: () => form.reset('code') });
    };

    const again = () => resend.post(route('signup.code.send'), { onSuccess: () => { setWait(resendSeconds); showToast('Enviamos un código nuevo', 'success'); } });

    return (
        <form onSubmit={submit} noValidate>
            <h2 className="signup-title">Revisa tu correo</h2>
            <p className="signup-lead">
                Enviamos un código de 6 dígitos a <strong>{email}</strong>. Escríbelo aquí para continuar; caduca en {codeMinutes} minutos y solo sirve una vez. Si no llega, revisa la carpeta de correo no deseado.
            </p>

            <AccessInput
                id="signup_code"
                label="Código de verificación"
                className="code-input"
                value={form.data.code}
                onChange={(e) => {
                    form.setData('code', e.target.value.replace(/\D/g, '').slice(0, 6));
                    form.clearErrors('code');
                }}
                message={form.errors.code || form.errors.email || ''}
                inputMode="numeric"
                autoComplete="one-time-code"
                placeholder="000000"
                maxLength={6}
                autoFocus
            />

            <Actions
                left={
                    <>
                        <button type="button" className="btn-ghost" onClick={() => go('signup.restart')}>Usar otro correo</button>
                        <button type="button" className="btn-ghost" onClick={again} disabled={wait > 0 || resend.processing}>
                            {wait > 0 ? `Reenviar en ${wait} s` : 'Reenviar código'}
                        </button>
                    </>
                }
            >
                <button type="submit" className="login-btn" disabled={form.processing}>
                    {form.processing ? 'Verificando…' : 'Verificar'}
                </button>
            </Actions>
        </form>
    );
}

function StepCompany({ email, draft, passwordMin }) {
    const { showToast } = useAccess();
    const form = useForm({
        admin_name: draft?.adminName ?? '',
        company_name: draft?.companyName ?? '',
        tax_id: draft?.taxId ?? '',
        phone: draft?.phone ?? '',
        workspace_code: draft?.workspaceCode ?? '',
        password: '',
        password_confirmation: '',
    });
    const [showPassword, setShowPassword] = useState(false);
    const [availability, setAvailability] = useState(null);
    const last = useRef('');
    const set = (key) => (e) => {
        form.setData(key, e.target.value);
        form.clearErrors(key);
    };

    // Live availability of the Workspace name (the server decides again when saving).
    useEffect(() => {
        const code = form.data.workspace_code.trim();

        if (!/^[A-Za-z0-9_-]{3,50}$/.test(code)) {
            setAvailability(null);
            return undefined;
        }

        const timer = setTimeout(() => {
            last.current = code;
            fetch(route('signup.availability', { code }), { headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? response.json() : null))
                .then((body) => last.current === code && setAvailability(body ? body.available : null))
                .catch(() => setAvailability(null));
        }, 400);

        return () => clearTimeout(timer);
    }, [form.data.workspace_code]);

    const submit = (e) => {
        e.preventDefault();
        form.post(route('signup.company'), { onError: () => showToast('Por favor, corrige los errores del formulario', 'error') });
    };

    const example = 'clinica-central';

    return (
        <form onSubmit={submit} noValidate>
            <h2 className="signup-title">Cuéntanos de tu empresa</h2>
            <p className="signup-lead">Con estos datos prepararemos tu Workspace. Todavía no se crea nada: eso ocurre cuando se confirme el pago.</p>

            <div className="signup-grid">
                <AccessInput id="admin_name" label="Tu nombre" value={form.data.admin_name} onChange={set('admin_name')} message={form.errors.admin_name} autoComplete="name" maxLength={120} autoFocus />
                <div className="input-group">
                    <label htmlFor="admin_email">Correo verificado</label>
                    <input id="admin_email" value={email} readOnly disabled className="success" />
                </div>
                <AccessPasswordInput id="password" label="Contraseña" value={form.data.password} onChange={set('password')} message={form.errors.password} placeholder={draft?.hasPassword ? 'Ya definida (déjala vacía para conservarla)' : `Mínimo ${passwordMin} caracteres`} autoComplete="new-password" visible={showPassword} onToggle={() => setShowPassword((v) => !v)} />
                <AccessPasswordInput id="password_confirmation" label="Repite la contraseña" value={form.data.password_confirmation} onChange={set('password_confirmation')} message={form.errors.password_confirmation} autoComplete="new-password" visible={showPassword} onToggle={() => setShowPassword((v) => !v)} />
                <AccessInput id="company_name" label="Nombre de la empresa" value={form.data.company_name} onChange={set('company_name')} message={form.errors.company_name} autoComplete="organization" maxLength={160} />
                <div>
                    <AccessInput id="tax_id" label="NIT o identificador fiscal (opcional)" value={form.data.tax_id} onChange={set('tax_id')} message={form.errors.tax_id} placeholder="900123456-7 · RFC · VAT · EIN" maxLength={20} />
                </div>
                <AccessInput id="phone" label="Teléfono de contacto" type="tel" value={form.data.phone} onChange={set('phone')} message={form.errors.phone} placeholder="+57 300 000 0000" autoComplete="tel" maxLength={20} />
                <div>
                    <AccessInput
                        id="workspace_code"
                        label="Nombre de tu Workspace"
                        value={form.data.workspace_code}
                        onChange={set('workspace_code')}
                        message={form.errors.workspace_code}
                        valid={availability === true && !form.errors.workspace_code}
                        placeholder={example}
                        autoCapitalize="none"
                        spellCheck={false}
                        maxLength={50}
                    />
                    {availability === true && !form.errors.workspace_code && <p className="signup-hint ok" role="status">Disponible</p>}
                    {availability === false && !form.errors.workspace_code && <p className="signup-hint bad" role="status">No disponible, prueba con otro</p>}
                </div>
            </div>

            <div className="signup-note">
                <div>
                    <strong>¿Qué es el Workspace?</strong> Tu Workspace es el espacio privado donde tu empresa gestionará sus operaciones, usuarios y módulos. Los datos de tu empresa estarán separados de los de otras organizaciones, aunque utilicen la misma solución. Con ese nombre (se guarda en mayúsculas) tu equipo entrará a la plataforma.
                    <span className="signup-example">Ejemplo ilustrativo, no es un Workspace existente: si tu empresa se llama «Clínica Central», puedes llamar a tu Workspace «{example}». Desde allí accederás a los módulos de Salud que incluya tu plan.</span>
                </div>
            </div>

            <Actions left={<button type="button" className="btn-ghost" onClick={() => go('signup.cancel')}>Cancelar</button>}>
                <button type="submit" className="login-btn" disabled={form.processing || availability === false}>
                    {form.processing ? 'Guardando…' : 'Continuar'}
                </button>
            </Actions>
        </form>
    );
}

function StepSolution({ solutions, selected }) {
    const [choice, setChoice] = useState(selected);
    const form = useForm({ solution: selected ?? '' });

    const submit = (e) => {
        e.preventDefault();
        form.transform(() => ({ solution: choice }));
        form.post(route('signup.solution'));
    };

    return (
        <form onSubmit={submit}>
            <h2 className="signup-title">Elige tu solución</h2>
            <p className="signup-lead">Selecciona la solución que mejor se ajusta a tu negocio. En el siguiente paso verás sus planes.</p>

            {solutions.length === 0 ? (
                <div className="signup-note warning" role="status">
                    Todavía no hay soluciones disponibles para contratar en línea. Escríbenos desde el botón de ayuda y un asesor te acompañará.
                </div>
            ) : (
                <div className="choice-grid" role="radiogroup" aria-label="Soluciones comerciales">
                    {solutions.map((solution) => (
                        <button type="button" key={solution.key} role="radio" aria-checked={choice === solution.key} className="choice-card" onClick={() => setChoice(solution.key)}>
                            <span className="choice-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d={solution.custom ? SOLUTION_ICONS.custom : SOLUTION_ICONS.default} /></svg>
                            </span>
                            <span className="choice-name">{solution.name}</span>
                            {solution.description && <span className="choice-text">{solution.description}</span>}
                            <span className="choice-tags">
                                {solution.modulesSummary.map((name) => <span className="choice-tag" key={name}>{name}</span>)}
                                {solution.modulesCount > solution.modulesSummary.length && <span className="choice-tag">+{solution.modulesCount - solution.modulesSummary.length} más</span>}
                            </span>
                        </button>
                    ))}
                </div>
            )}
            {form.errors.solution && <p className="signup-hint bad" role="alert">{form.errors.solution}</p>}

            <Actions left={<button type="button" className="btn-ghost" onClick={() => go('signup.back', { to: 'company' })}>Atrás</button>}>
                <button type="submit" className="login-btn" disabled={!choice || form.processing}>Continuar</button>
            </Actions>
        </form>
    );
}

function ModulesModal({ plan, onClose }) {
    const closeRef = useRef(null);

    useEffect(() => {
        closeRef.current?.focus();
        const onKey = (event) => event.key === 'Escape' && onClose();
        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, []);

    // Rendered in the body: the card has a backdrop filter, which would otherwise trap a fixed overlay inside it.
    return createPortal(
        <div className="signup-modal-backdrop" onClick={onClose}>
            <div className="signup-modal" role="dialog" aria-modal="true" aria-labelledby="modules-title" onClick={(e) => e.stopPropagation()}>
                <header>
                    <div>
                        <h2 id="modules-title">Módulos de {plan.name}</h2>
                        <p className="signup-hint">Solo informativo: los módulos se habilitan cuando se confirma el pago.</p>
                    </div>
                    <button type="button" ref={closeRef} className="signup-modal-close" onClick={onClose} aria-label="Cerrar">×</button>
                </header>
                {plan.modules.length === 0 ? (
                    <ul><li><span>Este plan no incluye módulos funcionales.</span></li></ul>
                ) : (
                    <ul>
                        {plan.modules.map((module) => (
                            <li key={module.key}>
                                <strong>{module.name}</strong>
                                <span>{module.description ?? 'Sin descripción.'}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>,
        document.body,
    );
}

const PERIOD_NAME = { monthly: 'Mensual', yearly: 'Anual' };

/** The price of every period the plan sells. With more than one, the customer picks (nothing is chosen for them); with one, it is shown as is. */
function PriceOptions({ prices, period, onPick, active }) {
    if (!prices.length) return <p className="choice-text">Precio no disponible</p>;

    if (prices.length === 1) {
        const [price] = prices;

        return (
            <p className="plan-price">
                <strong>{formatMoney(price.amount, price.currency)}</strong>
                <span>{price.currency} · {PERIOD[price.period]}</span>
            </p>
        );
    }

    return (
        <div className="period-options" role="radiogroup" aria-label="Periodicidad de pago">
            {prices.map((price) => (
                <button key={price.period} type="button" role="radio" className="period-option" aria-checked={active && period === price.period}
                    onClick={(e) => { e.stopPropagation(); onPick(price.period); }}>
                    <span>{PERIOD_NAME[price.period]}</span>
                    <strong>{formatMoney(price.amount, price.currency)} <span>{price.currency} · {PERIOD[price.period]}</span></strong>
                </button>
            ))}
        </div>
    );
}

function StepPlan({ plans }) {
    const [choice, setChoice] = useState(null);
    const [period, setPeriod] = useState(null);
    const [viewing, setViewing] = useState(null);
    const form = useForm({ plan_id: '', period: '' });
    const usable = (plan) => plan.purchasable || plan.requiresQuote;
    const chosen = plans.find((plan) => plan.id === choice);
    // A plan with two prices needs the customer's pick; a plan with one price, or sold by quote, does not.
    const ready = chosen && (chosen.requiresQuote || chosen.prices.length === 1 || period !== null);

    const pick = (plan, chosenPeriod = null) => {
        if (!usable(plan)) return;
        if (choice !== plan.id) setPeriod(plan.prices.length === 1 ? plan.prices[0].period : null);
        setChoice(plan.id);
        if (chosenPeriod) setPeriod(chosenPeriod);
    };

    const submit = (e) => {
        e.preventDefault();
        form.transform(() => ({ plan_id: choice, period: chosen?.requiresQuote ? '' : (period ?? chosen?.prices[0]?.period ?? '') }));
        form.post(route('signup.plan'));
    };

    return (
        <form onSubmit={submit}>
            <h2 className="signup-title">Elige tu plan</h2>
            <p className="signup-lead">Compara los planes de esta solución. Los precios y los módulos son los vigentes; elige además si prefieres pagar mensual o anualmente cuando el plan ofrece ambas opciones.</p>

            {plans.length === 0 ? (
                <div className="signup-note warning" role="status">Esta solución no tiene planes disponibles ahora. Vuelve atrás y elige otra.</div>
            ) : (
                <div className="choice-grid" role="radiogroup" aria-label="Planes">
                    {plans.map((plan) => (
                        <div key={plan.id} className="choice-card" role="radio" tabIndex={usable(plan) ? 0 : -1} aria-checked={choice === plan.id} aria-disabled={!usable(plan)}
                            onClick={() => pick(plan)}
                            onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && e.target === e.currentTarget && usable(plan) && (e.preventDefault(), pick(plan))}>
                            <span className="choice-name">{plan.name}</span>
                            {plan.requiresQuote
                                ? <p className="choice-text"><strong>Por cotización.</strong> Un asesor de CCG prepara una propuesta para tu empresa; no hay cobro inmediato.</p>
                                : <PriceOptions prices={plan.prices} period={period} active={choice === plan.id} onPick={(chosenPeriod) => pick(plan, chosenPeriod)} />}
                            {plan.description && <span className="choice-text">{plan.description}</span>}
                            {!usable(plan) && <span className="choice-text">Este plan todavía no está disponible para contratar en línea.</span>}
                            <span className="choice-tags">
                                <span className="choice-tag">{plan.modules.length} {plan.modules.length === 1 ? 'módulo' : 'módulos'}</span>
                                {(plan.limits ?? []).map((limit) => <span key={limit.key} className="choice-tag">{limit.label}: {limitText(limit)}</span>)}
                            </span>
                            <button type="button" className="plan-link" onClick={(e) => { e.stopPropagation(); setViewing(plan); }}>Ver módulos</button>
                        </div>
                    ))}
                </div>
            )}
            {chosen && !chosen.requiresQuote && chosen.prices.length > 1 && period === null && <p className="signup-hint" role="status">Elige mensual o anual para continuar.</p>}
            {(form.errors.plan || form.errors.period) && <p className="signup-hint bad" role="alert">{form.errors.plan ?? form.errors.period}</p>}

            <Actions left={<button type="button" className="btn-ghost" onClick={() => go('signup.back', { to: 'solution' })}>Atrás</button>}>
                <button type="submit" className="login-btn" disabled={!ready || form.processing}>Continuar</button>
            </Actions>
            {viewing && <ModulesModal plan={viewing} onClose={() => setViewing(null)} />}
        </form>
    );
}

function StepSummary({ email, draft, selected, documents }) {
    const { showToast } = useAccess();
    const [accepted, setAccepted] = useState([]);
    const [viewing, setViewing] = useState(false);
    const form = useForm({ accepted: [] });
    const quote = selected?.requiresQuote;
    const price = selected?.snapshot ?? null;
    const allAccepted = documents.every((document) => accepted.includes(document.key));

    const toggle = (key) => setAccepted((current) => (current.includes(key) ? current.filter((k) => k !== key) : [...current, key]));

    const contract = (e) => {
        e.preventDefault();
        form.transform(() => ({ accepted }));
        form.post(route('signup.contract'), { onError: () => showToast('Revisa los datos antes de continuar', 'error') });
    };

    const askQuote = () => router.post(route('signup.quote'), {}, { preserveScroll: true });

    return (
        <form onSubmit={contract}>
            <h2 className="signup-title">Revisa tu contratación</h2>
            <p className="signup-lead">Confirma que todo está bien. Puedes volver a cualquier paso para corregirlo.</p>

            {selected ? (
                <div className="summary-table">
                    <section>
                        <h3>Tu oferta</h3>
                        <dl>
                            <div><dt>Solución</dt><dd>{selected.solutionName}</dd></div>
                            <div><dt>Plan</dt><dd>{selected.name}</dd></div>
                            {!quote && price && <div><dt>Periodicidad</dt><dd>{PERIOD_NAME[price.period]}</dd></div>}
                            <div className="highlight"><dt>Precio</dt><dd>{quote ? 'Por cotización' : price ? `${formatMoney(price.amount, price.currency)} ${price.currency} · ${PERIOD[price.period]}` : 'No disponible'}</dd></div>
                        </dl>
                    </section>
                    <section>
                        <h3>Prestaciones y límites</h3>
                        <dl>
                            <div><dt>Módulos incluidos</dt><dd>{selected.modules.length} · <button type="button" className="plan-link" onClick={() => setViewing(true)}>Ver módulos</button></dd></div>
                            {(selected.limits ?? []).map((limit) => <div key={limit.key}><dt>{limit.label}</dt><dd>{limitText(limit)}</dd></div>)}
                        </dl>
                    </section>
                    <section>
                        <h3>Tu cuenta</h3>
                        <dl>
                            <div><dt>Empresa</dt><dd>{draft.companyName}</dd></div>
                            {draft.taxId && <div><dt>NIT o identificador fiscal</dt><dd>{draft.taxId}</dd></div>}
                            <div><dt>Workspace</dt><dd>{draft.workspaceCode}</dd></div>
                            <div><dt>Administrador</dt><dd>{draft.adminName} · {email}</dd></div>
                        </dl>
                    </section>
                </div>
            ) : (
                <div className="signup-note warning" role="alert">El plan elegido ya no está disponible. Vuelve atrás y elige otro.</div>
            )}

            {!quote && selected && !selected.purchasable && <div className="signup-note warning" role="alert">Este plan no tiene un precio disponible: no se puede contratar en línea.</div>}

            {!quote && documents.map((document) => (
                <label className="check-row" key={document.key}>
                    <input type="checkbox" checked={accepted.includes(document.key)} onChange={() => toggle(document.key)} />
                    <span>
                        Acepto {document.title} (versión {document.version}){' '}
                        <a href={route('legal.show', document.slug)} target="_blank" rel="noreferrer">leer</a>
                        {document.draft ? ' · texto en borrador, pendiente de revisión jurídica' : ''}
                    </span>
                </label>
            ))}
            {(form.errors.terms || form.errors.accepted) && <p className="signup-hint bad" role="alert">{form.errors.terms || form.errors.accepted}</p>}
            {form.errors.plan && <p className="signup-hint bad" role="alert">{form.errors.plan}</p>}

            <Actions left={<button type="button" className="btn-ghost" onClick={() => go('signup.back', { to: 'plan' })}>Atrás</button>}>
                {quote ? (
                    <button type="button" className="login-btn" onClick={askQuote}>Solicitar cotización</button>
                ) : (
                    <button type="submit" className="login-btn" disabled={!selected?.purchasable || !allAccepted || form.processing}>
                        {form.processing ? 'Procesando…' : 'Continuar al pago'}
                    </button>
                )}
            </Actions>
            {viewing && selected && <ModulesModal plan={selected} onClose={() => setViewing(false)} />}
        </form>
    );
}

/** The payment state, as it really is: a quote request, or a contract waiting for a payment that has not been confirmed. */
function StepPayment({ draft, payment }) {
    const quote = payment.status === 'quote_requested';

    return (
        <div>
            <h2 className="signup-title">{quote ? 'Solicitud de cotización recibida' : 'Contratación pendiente de pago'}</h2>

            {quote ? (
                <div className="signup-note success" role="status">
                    <div>
                        Registramos la solicitud de <strong>{draft.companyName}</strong>. El equipo comercial de CCG la revisará; hasta entonces no se crea ningún Workspace ni se realiza ningún cobro.
                    </div>
                </div>
            ) : (
                <>
                    <div className="signup-note warning" role="status">
                        <div>
                            <strong>Tu Workspace todavía no está activo.</strong> Guardamos tu contratación, pero el pago aún no está confirmado: no se ha cobrado nada y tu Workspace se crea y activa automáticamente cuando el pago se confirme.
                            {!payment.available && (
                                <span className="signup-example">El pago en línea todavía no está habilitado en este entorno. Escríbenos desde el botón de ayuda y te indicaremos cómo completar la contratación. Referencia: {payment.reference}</span>
                            )}
                        </div>
                    </div>
                </>
            )}

            <Actions left={<button type="button" className="btn-ghost" onClick={() => go('signup.back', { to: 'summary' })}>Revisar resumen</button>}>
                {!quote && <button type="button" className="login-btn" onClick={() => router.reload()}>Revisar estado</button>}
                <button type="button" className="btn-ghost" onClick={() => go('signup.cancel')}>Cancelar contratación</button>
            </Actions>
        </div>
    );
}

export default function Wizard(props) {
    const { step, steps } = props;
    const { flash, errors } = usePage().props;
    const { showToast } = useAccess();

    useEffect(() => {
        if (flash?.error) showToast(flash.error, 'error');
    }, [flash?.error]);

    return (
        <>
            <Head title="Crear cuenta" />
            <Steps steps={steps} />
            {errors?.signup && <div className="signup-note warning" role="alert">{errors.signup}</div>}
            {step === 'email' && <StepEmail email={props.email} mailUsable={props.mailUsable} codeMinutes={props.codeMinutes} offersAvailable={props.offersAvailable} />}
            {step === 'code' && <StepCode email={props.email} resendSeconds={props.resendSeconds} codeMinutes={props.codeMinutes} />}
            {step === 'company' && <StepCompany email={props.email} draft={props.draft} passwordMin={props.passwordMin} />}
            {step === 'solution' && <StepSolution solutions={props.solutions} selected={props.solutionKey} />}
            {step === 'plan' && <StepPlan plans={props.plans} />}
            {step === 'summary' && <StepSummary email={props.email} draft={props.draft} selected={props.selected} documents={props.documents} />}
            {step === 'payment' && <StepPayment draft={props.draft} payment={props.payment} />}
            <p className="signup-link-row">
                ¿Ya tienes una cuenta? <a href={route('pre-login')}>Inicia sesión</a>
            </p>
        </>
    );
}

Wizard.layout = (page) => <SignupLayout>{page}</SignupLayout>;
