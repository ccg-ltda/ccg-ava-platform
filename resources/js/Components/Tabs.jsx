import { Tab, TabGroup, TabList, TabPanel, TabPanels } from '@headlessui/react';

/**
 * Accessible tabs of a module (keyboard arrows, aria roles) built on Headless UI.
 * Only the active panel is rendered. Controlled: `selectedIndex` / `onChange`.
 *
 * `tabs`: [{ id, label, icon?, count? }]; `children`: one panel per tab, in the same order.
 */
export default function Tabs({ tabs, selectedIndex, onChange, children }) {
    const panels = Array.isArray(children) ? children : [children];

    return (
        <TabGroup selectedIndex={selectedIndex} onChange={onChange}>
            <TabList className="flex gap-1 overflow-x-auto overflow-y-hidden border-b border-line" aria-label="Secciones del módulo">
                {tabs.map(({ id, label, icon: Icon, count }) => (
                    <Tab
                        key={id}
                        className="relative flex shrink-0 items-center gap-2 px-3 py-3 text-sm sm:px-4 font-semibold whitespace-nowrap text-ink-muted outline-none transition-colors duration-150 hover:text-ink focus-visible:bg-primary-soft/60 data-selected:text-primary data-selected:after:absolute data-selected:after:inset-x-3 data-selected:after:-bottom-px data-selected:after:h-0.5 data-selected:after:rounded-full data-selected:after:bg-primary"
                    >
                        {Icon && <Icon className="size-4" aria-hidden="true" />}
                        {label}
                        {count !== undefined && (
                            <span className="hidden rounded-full bg-canvas px-2 py-0.5 text-[11px] font-bold text-ink-muted sm:inline-block">{count}</span>
                        )}
                    </Tab>
                ))}
            </TabList>

            <TabPanels className="mt-6">
                {panels.map((panel, index) => (
                    <TabPanel key={tabs[index].id} className="space-y-6 outline-none motion-safe:animate-fade-in">
                        {panel}
                    </TabPanel>
                ))}
            </TabPanels>
        </TabGroup>
    );
}
