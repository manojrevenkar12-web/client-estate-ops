/** One orchestrated moment: an app's detail settling in when it is opened. Everything else moves only when someone
    opens or closes it. */
export const settle = { type: "spring", stiffness: 320, damping: 34 } as const;
export const open = { duration: 0.18 } as const;
