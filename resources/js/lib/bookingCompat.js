// Pure rules of the new-appointment form (tested with `npm run test:js`): which services and professionals fit the choices made so far. They only narrow
// what the form offers; the server calculates the real availability again and refuses anything incompatible.

const sameId = (a, b) => String(a) === String(b);

/** The sedes where the service is offered (a service offered everywhere, or one that lists them). With no service chosen yet, none: the service comes first. */
export function locationsForService(locations, service) {
    if (!service) return [];

    return locations.filter((location) => service.everywhere !== false || (service.locationIds ?? []).some((id) => sameId(id, location.id)));
}

/** Services offered in the chosen sede (a service offered everywhere, or one that lists it). With no sede chosen, all of them. */
export function servicesForLocation(services, locationId) {
    if (locationId === '' || locationId === null || locationId === undefined) return services;

    return services.filter((service) => service.everywhere !== false || (service.locationIds ?? []).some((id) => sameId(id, locationId)));
}

/** Professionals who perform the service and work in the sede (hours with no sede count everywhere). Fields the server did not send do not exclude anyone. */
export function professionalsFor(professionals, serviceId, locationId) {
    return professionals.filter((professional) => {
        const performs = serviceId === '' || serviceId === null || serviceId === undefined || !professional.serviceIds || professional.serviceIds.some((id) => sameId(id, serviceId));
        const works = locationId === '' || locationId === null || locationId === undefined || professional.everywhere === true || !professional.locationIds || professional.locationIds.some((id) => sameId(id, locationId));

        return performs && works;
    });
}

/** Whether the professional chosen before still fits after the service or the sede changed ('' = anyone is always valid). */
export function stillCompatible(professionals, professionalId, serviceId, locationId) {
    return professionalId === '' || professionalId === null || professionalId === undefined || professionalsFor(professionals, serviceId, locationId).some((professional) => sameId(professional.id, professionalId));
}
