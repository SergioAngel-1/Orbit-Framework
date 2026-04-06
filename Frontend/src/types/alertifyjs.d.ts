declare module 'alertifyjs' {
  interface AlertifyNotifier {
    success(message: string, wait?: number, callback?: () => void): AlertifyNotifier;
    error(message: string, wait?: number, callback?: () => void): AlertifyNotifier;
    warning(message: string, wait?: number, callback?: () => void): AlertifyNotifier;
    message(message: string, wait?: number, callback?: () => void): AlertifyNotifier;
    closeAll(): AlertifyNotifier;
  }

  interface AlertifyDialogBuilder {
    set(key: string, value: any): AlertifyDialogBuilder;
    set(settings: object): AlertifyDialogBuilder;
    get(key: string): any;
    autoReset(value: boolean): AlertifyDialogBuilder;
    resizable(value: boolean): AlertifyDialogBuilder;
    closable(value: boolean): AlertifyDialogBuilder;
    maximizable(value: boolean): AlertifyDialogBuilder;
    pinnable(value: boolean): AlertifyDialogBuilder;
    movable(value: boolean): AlertifyDialogBuilder;
    padding(value: boolean): AlertifyDialogBuilder;
    overflow(value: boolean): AlertifyDialogBuilder;
    modal(value: boolean): AlertifyDialogBuilder;
    transition(value: string): AlertifyDialogBuilder;
    basic(): AlertifyDialogBuilder;
    frameless(): AlertifyDialogBuilder;
    reset(): AlertifyDialogBuilder;
    closeOthers(): AlertifyDialogBuilder;
  }

  interface AlertifyDialog extends AlertifyDialogBuilder {
    setContent(content: string): AlertifyDialog;
    showModal(): AlertifyDialog;
    show(): AlertifyDialog;
    close(): AlertifyDialog;
  }

  interface AlertifyDefaultsTheme {
    ok: string;
    cancel: string;
    input: string;
  }

  interface AlertifyDefaultsNotifier {
    position: string;
    delay: number;
    closeButton: boolean;
  }

  interface AlertifyDefaults {
    transition: string;
    theme: AlertifyDefaultsTheme;
    notifier: AlertifyDefaultsNotifier;
  }

  interface Alertify {
    defaults: AlertifyDefaults;
    dialog(message: string): AlertifyDialog;
    alert(message: string, onOkay?: () => void, onCancel?: () => void): AlertifyDialog;
    confirm(title: string, message: string, onOkay?: () => void, onCancel?: () => void): AlertifyDialog;
    prompt(title: string, message: string, defaultValue?: string, onOkay?: (event: any, value: string) => void, onCancel?: () => void): AlertifyDialog;
    success(message: string, wait?: number, callback?: () => void): AlertifyNotifier;
    error(message: string, wait?: number, callback?: () => void): AlertifyNotifier;
    warning(message: string, wait?: number, callback?: () => void): AlertifyNotifier;
    message(message: string, wait?: number, callback?: () => void): AlertifyNotifier;
    notify(message: string, type?: string, wait?: number, callback?: () => void): AlertifyNotifier;
  }

  const alertify: Alertify;
  export default alertify;
}
